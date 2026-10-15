<?php

namespace Tests\Feature\SupplyChain;

use App\Events\SupplyChainAuditEvent;
use App\Jobs\CheckSupplyChainJob;
use App\Jobs\StartSupplyChainJob;
use App\Models\ImageSbomDocument;
use App\Models\ImageSecurityReport;
use App\Models\RuntimeBuild;
use App\Services\SupplyChain\SupplyChainPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\FakesSupplyChainCluster;
use Tests\TestCase;

/**
 * Build ready → report → supply-chain Job in the region → its log → stored results:
 * steps per region settings and image kind, the Job manifest, recorded SBOMs / counts /
 * findings / signature, failures and the build-ready hooks.
 */
class SupplyChainPipelineTest extends TestCase
{
    use FakesSupplyChainCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSupplyChainCluster();
    }

    protected function tearDown(): void
    {
        $this->tearDownSupplyChain();
        parent::tearDown();
    }

    private function pipeline(): SupplyChainPipeline
    {
        return app(SupplyChainPipeline::class);
    }

    // ── Steps ───────────────────────────────────────────────────────────────

    public function test_steps_follow_the_region_settings(): void
    {
        Bus::fake([StartSupplyChainJob::class]);
        $region = $this->scRegion();

        $report = $this->pipeline()->queueForVersion($this->scBuiltVersion($region));
        $this->assertSame(['sbom', 'attach', 'scan'], $report->steps, 'defaults: SBOM + referrers + scan, no key yet');
        $this->assertSame('pending', $report->status);
        $this->assertSame('skipped', $report->signature_status);
        Bus::assertDispatched(StartSupplyChainJob::class, fn ($job) => $job->report->is($report));

        $this->scSigningReady($region);
        $report = $this->pipeline()->queueForVersion($this->scBuiltVersion($region));
        $this->assertSame(['sbom', 'attach', 'scan', 'sign', 'attest'], $report->steps);

        $this->scSettings($region, ['attest_enabled' => false, 'sbom_enabled' => false]);
        $report = $this->pipeline()->queueForVersion($this->scBuiltVersion($region));
        $this->assertSame(['scan', 'sign'], $report->steps);

        $this->scSettings($region, ['scan_enabled' => false, 'signing_enabled' => false]);
        $this->assertNull($this->pipeline()->queueForVersion($this->scBuiltVersion($region)), 'nothing to do');
    }

    public function test_code_mounted_versions_and_demo_regions_have_no_report(): void
    {
        Bus::fake([StartSupplyChainJob::class]);
        $region = $this->scRegion();

        $mounted = $this->scBuiltVersion($region, ['build_image' => null]);
        $this->assertNull($this->pipeline()->queueForVersion($mounted));

        $demo = $this->scRegion(['is_demo' => true]);
        $this->assertNull($this->pipeline()->queueForVersion($this->scBuiltVersion($demo)));

        Bus::assertNotDispatched(StartSupplyChainJob::class);
    }

    public function test_a_spin_app_is_signed_and_not_handed_to_syft_or_trivy(): void
    {
        Bus::fake([StartSupplyChainJob::class]);
        $region = $this->scRegion();
        $version = $this->scWasmVersion($region);

        $this->assertNull($this->pipeline()->queueForVersion($version), 'SBOM and scan on, no key: nothing applies to a Spin app');

        $this->scSigningReady($region);
        $report = $this->pipeline()->queueForVersion($version);

        $this->assertSame(ImageSecurityReport::KIND_WASM, $report->image_kind);
        $this->assertSame($version->build_image, $report->image_ref);
        $this->assertSame(['sign'], $report->steps);
        $this->assertSame('pending', $report->signature_status);
        foreach (['sbom_status', 'referrer_status', 'scan_status', 'attestation_status'] as $column) {
            $this->assertSame('skipped', $report->{$column}, $column);
        }
        foreach (['sbom', 'attach', 'scan', 'attest'] as $step) {
            $this->assertSame('skipped', $report->step_results[$step]['status']);
            $this->assertStringContainsString('no container filesystem', $report->step_results[$step]['message']);
        }

        $report->forceFill(['status' => 'completed', 'signature_status' => 'ok', 'image_digest' => self::SC_DIGEST])->save();
        $this->assertNull($this->pipeline()->queueForVersion($version, rescan: true), 'no re-scan of a Spin app');
    }

    public function test_external_caas_images_only_when_the_region_scans_them(): void
    {
        Bus::fake([StartSupplyChainJob::class]);
        $region = $this->scRegion();
        $version = $this->scCaasVersion($region, 'nginx:1.27-alpine');

        $this->assertNull($this->pipeline()->queueForVersion($version));

        $this->scSigningReady($region, ['scan_external_images' => true]);
        $report = $this->pipeline()->queueForVersion($version);

        $this->assertSame(ImageSecurityReport::KIND_EXTERNAL, $report->image_kind);
        $this->assertSame('docker.io/library/nginx:1.27-alpine', $report->image_ref);
        $this->assertSame(['sbom', 'scan'], $report->steps, 'never attach to / sign an image RunR does not own');
    }

    public function test_a_rescan_keeps_the_sbom_and_the_signature(): void
    {
        Bus::fake([StartSupplyChainJob::class]);
        $region = $this->scRegion();
        $this->scSigningReady($region);
        $version = $this->scBuiltVersion($region);

        $report = $this->pipeline()->queueForVersion($version);
        $report->forceFill([
            'status' => 'completed', 'sbom_status' => 'ok', 'referrer_status' => 'ok', 'scan_status' => 'ok',
            'signature_status' => 'ok', 'attestation_status' => 'ok', 'image_digest' => self::SC_DIGEST,
        ])->save();

        $again = $this->pipeline()->queueForVersion($version, rescan: true);

        $this->assertSame($report->id, $again->id, 'one report per version');
        $this->assertSame(['scan', 'attest'], $again->steps);
        $this->assertSame('ok', $again->sbom_status);
        $this->assertSame('ok', $again->signature_status);
        $this->assertSame('pending', $again->scan_status);
        $this->assertSame(self::SC_DIGEST, $again->image_digest, 'the re-scan pins the digest');
    }

    // ── Cluster ─────────────────────────────────────────────────────────────

    public function test_submit_creates_the_namespace_and_the_job(): void
    {
        Bus::fake([StartSupplyChainJob::class, CheckSupplyChainJob::class]);
        $region = $this->scRegion();
        $this->scSigningReady($region);
        $this->k8sGet['/api/v1/namespaces/runr-supply-chain/secrets/runr-cosign-key'] = null; // not on the cluster yet

        $report = $this->pipeline()->queueForVersion($this->scBuiltVersion($region));
        $this->pipeline()->submit($report->fresh());

        $this->assertNotEmpty($this->scCalls('PATCH', '/api/v1/namespaces/runr-supply-chain'), 'namespace applied');

        // The key Secret is restored from the API's encrypted copy
        $secret = collect($this->scCalls('PATCH', '/namespaces/runr-supply-chain/secrets/runr-cosign-key'))->first()['body'];
        $this->assertSame('pw', base64_decode($secret['data']['cosign.password']));

        $job = $this->scCreated('/apis/batch/v1/namespaces/runr-supply-chain/jobs');
        $this->assertSame('sc-'.strtolower($report->id), $job['metadata']['name']);
        $env = collect($job['spec']['template']['spec']['containers'][0]['env'])->pluck('value', 'name');
        $this->assertSame($report->image_ref, $env['IMAGE']);
        $this->assertSame('sbom,attach,scan,sign,attest', $env['RUNR_STEPS']);

        $report->refresh();
        $this->assertSame('running', $report->status);
        $this->assertSame('running', $report->scan_status);
        $this->assertNotNull($report->started_at);
        Bus::assertDispatched(CheckSupplyChainJob::class);
    }

    public function test_submit_without_the_key_on_the_cluster_or_in_the_api_drops_signing(): void
    {
        Bus::fake([StartSupplyChainJob::class, CheckSupplyChainJob::class]);
        $region = $this->scRegion();
        $this->scSigningReady($region);
        $this->k8sGet['/api/v1/namespaces/runr-supply-chain/secrets/runr-cosign-key'] = null;
        $this->k8sFail['/secrets/runr-cosign-key'] = 500;

        $report = $this->pipeline()->queueForVersion($this->scBuiltVersion($region));
        $this->pipeline()->submit($report->fresh());

        $report->refresh();
        $this->assertSame(['sbom', 'attach', 'scan'], $report->steps);
        $this->assertSame('failed', $report->signature_status);
        $this->assertStringContainsString('signing key', (string) $report->error);
    }

    public function test_a_finished_job_is_recorded(): void
    {
        Event::fake([SupplyChainAuditEvent::class]);
        $region = $this->scRegion();
        $settings = $this->scSigningReady($region);
        $this->k8sGet['/api/v1/namespaces/runr-supply-chain/secrets/runr-cosign-key'] = ['metadata' => ['annotations' => ['runr.sh/key-fingerprint' => $settings->signing_key_fingerprint]]];
        $this->podLog = $this->scPipelineLog(['sbom' => 'ok', 'attach' => 'ok', 'scan' => 'ok', 'sign' => 'ok', 'attest' => 'ok']);

        // sync queue: StartSupplyChainJob → submit → CheckSupplyChainJob → collect
        $report = $this->pipeline()->queueForVersion($version = $this->scBuiltVersion($region));
        $report->refresh();

        $this->assertSame('completed', $report->status);
        $this->assertSame(self::SC_DIGEST, $report->image_digest);
        foreach (['sbom_status', 'referrer_status', 'scan_status', 'signature_status', 'attestation_status'] as $column) {
            $this->assertSame('ok', $report->{$column}, $column);
        }
        $this->assertSame([2, 2, 2, 1, 1], [$report->critical_count, $report->high_count, $report->medium_count, $report->low_count, $report->unknown_count]);
        $this->assertSame([1, 2], [$report->fixable_critical_count, $report->fixable_high_count]);
        $this->assertSame(8, $report->findings_total);
        $this->assertSame('CVE-2024-45491', $report->findings[0]['id']);
        $this->assertSame(6, $report->sbom_summary['packages']);
        $this->assertSame('0.58.1', $report->scanner['version']);
        $this->assertSame('2026-10-10T06:00:00Z', $report->scanner['db_updated_at']);
        $this->assertSame($settings->signing_key_fingerprint, $report->signature_key_fingerprint);
        $this->assertNotNull($report->signed_at);
        $this->assertNull($report->error);

        $this->assertSame(['cyclonedx-json', 'spdx-json'], $report->sbomDocuments()->orderBy('format')->pluck('format')->all());
        $spdx = $report->sbomDocuments()->where('format', 'spdx-json')->first();
        $this->assertSame('SPDX-2.3', json_decode($spdx->document(), true)['spdxVersion']);
        $this->assertSame(hash('sha256', $spdx->document()), $spdx->sha256);

        $this->assertSame($report->id, ImageSecurityReport::forVersion($version)->id);
        Event::assertDispatched(SupplyChainAuditEvent::class, fn ($e) => $e->action === 'image.signed' && $e->details['digest'] === self::SC_DIGEST);
    }

    public function test_failed_steps_are_recorded_per_step(): void
    {
        $region = $this->scRegion();
        $this->podLog = $this->scPipelineLog(['sbom' => 'ok', 'attach' => 'failed', 'scan' => 'ok']);

        $report = $this->pipeline()->queueForVersion($this->scBuiltVersion($region))->fresh();

        $this->assertSame('completed', $report->status);
        $this->assertSame('ok', $report->sbom_status);
        $this->assertSame('failed', $report->referrer_status);
        $this->assertSame('ok', $report->scan_status);
        $this->assertSame('Failed: attach', $report->error);
        $this->assertSame('attach went wrong', $report->step_results['attach']['message']);
    }

    public function test_a_job_that_died_without_its_results_fails_the_report(): void
    {
        $region = $this->scRegion();
        $this->jobStatus = ['failed' => 1, 'conditions' => [['type' => 'Failed', 'reason' => 'DeadlineExceeded', 'message' => 'Job was active longer than specified deadline']]];
        $this->podLog = 'RUNR_DIGEST '.self::SC_DIGEST."\nRUNR_STEP sbom ok\n";

        $report = $this->pipeline()->queueForVersion($this->scBuiltVersion($region))->fresh();

        $this->assertSame('failed', $report->status);
        $this->assertSame('ok', $report->sbom_status, 'what finished is kept');
        $this->assertSame('failed', $report->scan_status);
        $this->assertStringContainsString('DeadlineExceeded', (string) $report->error);
    }

    public function test_an_oversized_scan_report_fails_the_scan_step(): void
    {
        $region = $this->scRegion();
        $this->podLog = "RUNR_STEP sbom ok\nRUNR_STEP scan ok\nRUNR_STEP inline-trivy skipped ".base64_encode('too large')."\nRUNR_DONE\n";

        $report = $this->pipeline()->queueForVersion($this->scBuiltVersion($region))->fresh();

        $this->assertSame('failed', $report->scan_status);
        $this->assertStringContainsString('inline size limit', $report->step_results['scan']['message']);
    }

    public function test_a_missing_job_fails_the_report(): void
    {
        Bus::fake([StartSupplyChainJob::class]);
        $region = $this->scRegion();
        $report = $this->pipeline()->queueForVersion($this->scBuiltVersion($region));
        $report->forceFill(['status' => 'running', 'job_name' => 'sc-gone'])->save();
        $this->k8sGet['/apis/batch/v1/namespaces/runr-supply-chain/jobs/sc-gone'] = null;

        $this->assertTrue($this->pipeline()->collect($report));
        $this->assertSame('failed', $report->fresh()->status);
    }

    public function test_a_running_job_is_checked_again(): void
    {
        Bus::fake([StartSupplyChainJob::class, CheckSupplyChainJob::class]);
        $region = $this->scRegion();
        $this->jobStatus = ['active' => 1];
        $report = $this->pipeline()->queueForVersion($this->scBuiltVersion($region));
        $report->forceFill(['status' => 'running', 'job_name' => 'sc-x'])->save();

        (new CheckSupplyChainJob($report))->handle($this->pipeline());

        Bus::assertDispatched(CheckSupplyChainJob::class, fn ($job) => $job->attempt === 2);
        $this->assertSame('running', $report->fresh()->status);
    }

    // ── Runtime images ──────────────────────────────────────────────────────

    public function test_runtime_images_get_a_report_of_their_own(): void
    {
        Bus::fake([StartSupplyChainJob::class]);
        $region = $this->scRegion();
        $build = RuntimeBuild::create([
            'runtime_id' => 'q',
            'version' => '1.0',
            'knative_region_id' => $region->id,
            'status' => RuntimeBuild::STATUS_READY,
            'image' => self::SC_ZOT.'/runr-runtimes/q:1.0-abcdef123456',
            'digest' => str_repeat('a', 64),
        ]);

        $report = $this->pipeline()->queueForRuntimeBuild($build);

        $this->assertSame(ImageSecurityReport::SUBJECT_RUNTIME_BUILD, $report->subject_type);
        $this->assertSame(ImageSecurityReport::KIND_RUNTIME, $report->image_kind);
        $this->assertNull($report->project_id);
        $this->assertSame($report->id, ImageSecurityReport::forRuntimeBuild($build)->id);
    }

    // ── Hooks ───────────────────────────────────────────────────────────────

    public function test_a_version_that_became_ready_starts_its_run(): void
    {
        config(['supply_chain.enabled' => true]);
        Bus::fake([StartSupplyChainJob::class]);
        $region = $this->scRegion();
        $version = $this->scBuiltVersion($region, ['build_status' => 'building', 'build_image' => null]);

        Bus::assertNotDispatched(StartSupplyChainJob::class);

        $version->update(['build_image' => self::SC_ZOT.'/runr-builds/x:v1']);
        Bus::assertNotDispatched(StartSupplyChainJob::class, 'still building');

        $version->update(['build_status' => 'ready']);
        Bus::assertDispatchedTimes(StartSupplyChainJob::class, 1);

        $version->update(['commit_message' => 'unrelated']);
        Bus::assertDispatchedTimes(StartSupplyChainJob::class, 1);
    }

    public function test_hooks_are_off_with_the_master_switch(): void
    {
        config(['supply_chain.enabled' => false]);
        Bus::fake([StartSupplyChainJob::class]);
        $version = $this->scBuiltVersion($this->scRegion(), ['build_status' => 'building']);

        $version->update(['build_status' => 'ready']);

        Bus::assertNotDispatched(StartSupplyChainJob::class);
    }

    public function test_a_hook_failure_never_breaks_the_build(): void
    {
        config(['supply_chain.enabled' => true]);
        $this->mock(SupplyChainPipeline::class, fn ($m) => $m->shouldReceive('queueForVersion')->andThrow(new \RuntimeException('cluster down')));
        $version = $this->scBuiltVersion($this->scRegion(), ['build_status' => 'building']);

        $version->update(['build_status' => 'ready']);

        $this->assertSame('ready', $version->fresh()->build_status);
    }

    public function test_a_ready_runtime_image_and_a_new_caas_version_start_runs(): void
    {
        config(['supply_chain.enabled' => true]);
        Bus::fake([StartSupplyChainJob::class]);
        $region = $this->scRegion();
        $this->scSettings($region, ['scan_external_images' => true]);

        $this->scCaasVersion($region, 'ghcr.io/acme/web:2');
        Bus::assertDispatchedTimes(StartSupplyChainJob::class, 1);

        $build = RuntimeBuild::create([
            'runtime_id' => 'q', 'version' => '1.0', 'knative_region_id' => $region->id,
            'status' => RuntimeBuild::STATUS_BUILDING, 'image' => self::SC_ZOT.'/runr-runtimes/q:1.0-x', 'digest' => str_repeat('b', 64),
        ]);
        $build->update(['status' => RuntimeBuild::STATUS_READY]);
        Bus::assertDispatchedTimes(StartSupplyChainJob::class, 2);
    }

    public function test_reports_are_deleted_with_their_version(): void
    {
        Bus::fake([StartSupplyChainJob::class]);
        $version = $this->scBuiltVersion($this->scRegion());
        $report = $this->pipeline()->queueForVersion($version);
        $report->sbomDocuments()->create(['format' => 'spdx-json', 'content' => 'x', 'size_bytes' => 1, 'sha256' => 'y']);

        $version->delete();

        $this->assertNull(ImageSecurityReport::find($report->id));
        $this->assertSame(0, ImageSbomDocument::count());
    }
}
