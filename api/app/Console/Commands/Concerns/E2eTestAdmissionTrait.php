<?php

namespace App\Console\Commands\Concerns;

/**
 * E2E of what decides whether a project serves and deploys (test:e2e --suite=admission,
 * runtime/lib/test-admission.sh; the stack runs with the Billing module on), on one Python HTTP
 * function in the e2e region:
 *  - adm-stop                POST /projects/{p}/stop: the function stops serving (its route is
 *                            gone: 404 / 503), a deploy answers 409 project_stopped, a second stop
 *                            409 project_already_stopped
 *  - adm-resume              POST /projects/{p}/resume answers the redeploy's deployment_id; it
 *                            becomes active and the function answers 200 again
 *  - adm-region-maintenance  region_status maintenance: the function's host answers 503
 *                            {"error":"maintenance"} (the region's status responder), a deploy 422
 *  - adm-region-inactive     region_status inactive: 404 {"error":"region_inactive"}, a deploy 422
 *  - adm-region-active       back to active: the function answers 200 again
 *  - adm-fixed-required      the region's billing type set to fixed: a deploy without an approved
 *                            allocation answers 422 fixed_allocation_required
 *  - adm-fixed-exceeded      a request below what the project runs (100m / 128 MB against 250m /
 *                            128 MB plus the queue-proxy) answers 422 scale_down_first; with an
 *                            approved 1 vCPU / 1 GB a deploy whose worst case is above it (max_scale
 *                            5 = 5 × 350m / 228 MB) answers 422 fixed_allocation_exceeded
 *  - adm-fixed-within        a new approved allocation of 2 vCPU / 2 GB: the deploy is admitted, becomes
 *                            active and the function answers 200; deleting the project ends the
 *                            allocation and the region's type can change back to payg
 */
trait E2eTestAdmissionTrait
{
    private const ADM_PYTHON = <<<'PY'
def handler(event, context):
    return {"statusCode": 200, "body": {"ok": True, "suite": "admission"}}
PY;

    private function runAdmissionSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Project admission (stop / resume, region status, fixed-region allocations) ──');

        $tests = [$this->makeTestEntry('adm-function', [
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => null,
            'trigger_type' => 'http',
            'code' => self::ADM_PYTHON,
        ], $regionId)];
        $this->runTestsSequentially($tests);
        $test = $tests[0];
        if ($test['steps']['ready'] !== true) {
            return $tests;
        }
        $test['steps']['verify'] = $this->admWaitHttp($test['slug'], ['200'], 180);
        $tests[0] = $test;
        if ($test['steps']['verify'] !== true) {
            return $tests;
        }

        $tests[] = $this->admCheck('adm-stop', fn () => $this->admStop($test));
        $tests[] = $this->admCheck('adm-resume', fn () => $this->admResume($test));
        $tests[] = $this->admCheck('adm-region-maintenance', fn () => $this->admRegionStatus($test, 'maintenance', '503', 'maintenance'));
        $tests[] = $this->admCheck('adm-region-inactive', fn () => $this->admRegionStatus($test, 'inactive', '404', 'region_inactive'));
        $tests[] = $this->admCheck('adm-region-active', fn () => $this->admRegionStatus($test, 'active', '200', 'admission'));
        $tests[] = $this->admCheck('adm-fixed-required', fn () => $this->admFixedRequired($test));
        $tests[] = $this->admCheck('adm-fixed-exceeded', fn () => $this->admFixedExceeded($test));
        $tests[] = $this->admCheck('adm-fixed-within', fn () => $this->admFixedWithin($test));

        return $tests;
    }

    private function admStop(array $test): bool|string
    {
        $stop = $this->api()->post("/api/projects/{$test['projectId']}/stop", ['reason' => 'e2e: the October bill is unpaid']);
        if ($stop->status() !== 200 || $stop->json('data.id') !== $test['projectId']) {
            return 'stop answered '.$stop->status().': '.mb_substr($stop->body(), 0, 300);
        }

        $served = $this->admWaitHttp($test['slug'], ['404', '503'], 180);
        if ($served !== true) {
            return 'after the stop: '.$served;
        }

        $deploy = $this->api()->post("/api/projects/{$test['projectId']}/deploy", ['version_id' => $test['versionId']]);
        if ($deploy->status() !== 409 || $deploy->json('code') !== 'project_stopped') {
            return 'deploy of the stopped project answered '.$deploy->status().' '.$deploy->json('code').' (want 409 project_stopped)';
        }

        $again = $this->api()->post("/api/projects/{$test['projectId']}/stop", ['reason' => 'e2e: second stop']);

        return $again->status() === 409 && $again->json('code') === 'project_already_stopped'
            ? true
            : 'second stop answered '.$again->status().' '.$again->json('code').' (want 409 project_already_stopped)';
    }

    private function admResume(array $test): bool|string
    {
        $resume = $this->api()->post("/api/projects/{$test['projectId']}/resume");
        if ($resume->status() !== 200 || ! $resume->json('deployment_id')) {
            return 'resume answered '.$resume->status().': '.mb_substr($resume->body(), 0, 300);
        }

        $ready = $test;
        $ready['steps']['ready'] = null;
        $this->waitSingleReady($ready);
        if ($ready['steps']['ready'] !== true) {
            return 'redeploy after the resume: '.$ready['steps']['ready'];
        }

        $served = $this->admWaitHttp($test['slug'], ['200'], 180);

        return $served === true ? true : 'after the resume: '.$served;
    }

    /** region_status $status: the function's host answers $code with $needle in the body; a deploy is refused unless active. */
    private function admRegionStatus(array $test, string $status, string $code, string $needle): bool|string
    {
        $update = $this->api()->put("/api/knative-regions/{$test['regionId']}", ['region_status' => $status]);
        if (! $update->successful()) {
            return "region_status {$status} answered ".$update->status().': '.mb_substr($update->body(), 0, 300);
        }

        $served = $this->admWaitHttp($test['slug'], [$code], 240, $needle);
        if ($served !== true) {
            return "region {$status}: {$served}";
        }
        if ($status === 'active') {
            return true;
        }

        $deploy = $this->api()->post("/api/projects/{$test['projectId']}/deploy", ['version_id' => $test['versionId']]);

        return $deploy->status() === 422
            ? true
            : "deploy in a region {$status} answered ".$deploy->status().' (want 422): '.mb_substr($deploy->body(), 0, 200);
    }

    private function admFixedRequired(array $test): bool|string
    {
        $type = $this->api()->put("/api/billing/regions/{$test['regionId']}", ['billing_type' => 'fixed']);
        if (! $type->successful() || $type->json('data.billing_type') !== 'fixed') {
            return 'billing type fixed answered '.$type->status().': '.mb_substr($type->body(), 0, 300);
        }

        $deploy = $this->api()->post("/api/projects/{$test['projectId']}/deploy", ['version_id' => $test['versionId']]);

        return $deploy->status() === 422 && $deploy->json('code') === 'fixed_allocation_required'
            ? true
            : 'deploy without an allocation answered '.$deploy->status().' '.$deploy->json('code').' (want 422 fixed_allocation_required)';
    }

    private function admFixedExceeded(array $test): bool|string
    {
        // The allocation is a real limit: less than what runs cannot be requested
        $small = $this->api()->post("/api/projects/{$test['projectId']}/billing/fixed-requests", [
            'cpu_m' => 100, 'memory_mb' => 128, 'gpu_count' => 0, 'note' => 'e2e',
        ]);
        if ($small->status() !== 422 || $small->json('code') !== 'scale_down_first') {
            return 'a request below what runs answered '.$small->status().' '.$small->json('code').' (want 422 scale_down_first): '.mb_substr($small->body(), 0, 300);
        }

        $approved = $this->admApproveAllocation($test, 1000, 1024);
        if ($approved !== true) {
            return $approved;
        }

        $deploy = $this->api()->post("/api/projects/{$test['projectId']}/deploy", ['version_id' => $test['versionId'], 'deploy_config' => ['max_scale' => 5]]);

        return $deploy->status() === 422 && $deploy->json('code') === 'fixed_allocation_exceeded'
            ? true
            : 'deploy above the allocation answered '.$deploy->status().' '.$deploy->json('code').' (want 422 fixed_allocation_exceeded): '.mb_substr($deploy->body(), 0, 400);
    }

    private function admFixedWithin(array $test): bool|string
    {
        $approved = $this->admApproveAllocation($test, 2000, 2048);
        if ($approved !== true) {
            return $approved;
        }

        $billing = $this->api()->get("/api/projects/{$test['projectId']}/billing");
        if ((int) $billing->json('allocation.cpu_m') !== 2000 || $billing->json('allocation.status') !== 'approved') {
            return 'project billing does not show the 2 vCPU allocation: '.mb_substr($billing->body(), 0, 300);
        }

        $ready = $test;
        $ready['steps']['deploy'] = null;
        $ready['steps']['ready'] = null;
        if (! $this->deploySingleTest($ready)) {
            return 'deploy within the allocation: '.$ready['steps']['deploy'];
        }
        sleep(5);
        $this->waitSingleReady($ready);
        if ($ready['steps']['ready'] !== true) {
            return 'deploy within the allocation: '.$ready['steps']['ready'];
        }
        $served = $this->admWaitHttp($test['slug'], ['200'], 180);
        if ($served !== true) {
            return 'within the allocation: '.$served;
        }

        // Deleting the project ends its allocation: the region's type is no longer locked
        $delete = $this->api()->delete("/api/projects/{$test['projectId']}");
        if (! $delete->successful()) {
            return 'project delete answered '.$delete->status();
        }
        $type = $this->api()->put("/api/billing/regions/{$test['regionId']}", ['billing_type' => 'payg']);

        return $type->successful() && $type->json('data.billing_type') === 'payg'
            ? true
            : 'billing type back to payg after the project was deleted answered '.$type->status().' '.$type->json('code').': '.mb_substr($type->body(), 0, 200);
    }

    /** A fixed resource request of the project, approved by the administrator (the region's seller). */
    private function admApproveAllocation(array $test, int $cpuM, int $memoryMb): bool|string
    {
        $request = $this->api()->post("/api/projects/{$test['projectId']}/billing/fixed-requests", [
            'cpu_m' => $cpuM, 'memory_mb' => $memoryMb, 'gpu_count' => 0, 'note' => 'e2e',
        ]);
        $id = $request->json('data.id');
        if ($request->status() !== 201 || ! $id) {
            return "fixed request {$cpuM}m / {$memoryMb} MB answered ".$request->status().': '.mb_substr($request->body(), 0, 300);
        }

        $approve = $this->api()->post("/api/billing/fixed-requests/{$id}/approve", ['note' => 'e2e']);

        return $approve->successful()
            ? true
            : "approval of {$cpuM}m / {$memoryMb} MB answered ".$approve->status().': '.mb_substr($approve->body(), 0, 300);
    }

    /**
     * Polls the function's host from inside the cluster until it answers one of $codes (and, when
     * given, a body containing $needle); true, or the last answer.
     *
     * @param  list<string>  $codes
     */
    private function admWaitHttp(string $slug, array $codes, int $timeout, ?string $needle = null): bool|string
    {
        $deadline = time() + $timeout;
        $last = 'no answer';
        while (time() < $deadline) {
            $res = $this->curlFunction($slug, '/', false);
            if (is_array($res)) {
                [$status, , $body] = $res;
                if (in_array($status, $codes, true) && ($needle === null || str_contains((string) $body, $needle))) {
                    return true;
                }
                $last = "HTTP {$status} ".mb_substr(trim((string) $body), 0, 160);
            } else {
                $last = $res;
            }
            sleep(5);
        }

        return 'want HTTP '.implode('/', $codes).($needle ? " with \"{$needle}\"" : '')." within {$timeout}s, last: {$last}";
    }

    private function admCheck(string $name, callable $check): array
    {
        $entry = $this->makeTestEntry($name, ['service_type' => 'admission', 'trigger_type' => 'http'], (string) ($this->e2eRegion->id ?? ''));
        $entry['startTime'] = microtime(true);
        try {
            $result = $check();
        } catch (\Throwable $e) {
            $result = $e::class.': '.$e->getMessage();
        }
        $entry['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $result === true ? true : (string) $result];
        $entry = $this->finishTest($entry);

        $state = $result === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->line("  {$state} {$name}".($result === true ? '' : ': '.mb_substr((string) $result, 0, 400)));

        return $entry;
    }
}
