<?php

namespace Tests\Feature;

use App\Jobs\InstallRegionRegistryJob;
use App\Jobs\InstallRegistryAndCreateRegionJob;
use App\Services\ZotRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\Support\QueuedJobTimeouts;
use Tests\TestCase;

/**
 * The internal registry's PVC test (ZotRegistryService::testPvcBound) gave the test claim a fixed
 * 60 s to bind. A WaitForFirstConsumer StorageClass binds only once the consumer pod is scheduled,
 * its image pulled and the provisioner created the volume: on a busy cluster that took longer and
 * the registry setup failed with "did not provision a volume within 60s". The claim now gets
 * knative.registry_pvc_test_seconds (RUNR_REGISTRY_PVC_TEST_SECONDS, 180 s, 30 to 900), the
 * failure names those seconds and what the test saw, and the registry setup jobs' timeout grows
 * with the wait, below Horizon's limits. The cluster's API is faked, Sleep too.
 */
class RegistryPvcTestWaitTest extends TestCase
{
    use FakesObservabilityCluster;
    use RefreshDatabase;

    private const NS = 'runr-registry';

    private const PVCS = '/api/v1/namespaces/runr-registry/persistentvolumeclaims/';

    /** @var list<string> the phase each look at the claim answers, the last one repeated */
    private array $phases = ['Pending'];

    private int $looks = 0;

    private ?array $pod = null;

    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->fakeObservabilityCluster();

        $this->k8sRoutes['GET '.self::PVCS] = function () {
            $phase = $this->phases[min($this->looks++, count($this->phases) - 1)];

            return [200, ['kind' => 'PersistentVolumeClaim', 'status' => ['phase' => $phase]]];
        };
        $this->k8sRoutes['GET /api/v1/namespaces/runr-registry/pods/'] = fn () => $this->pod ? [200, $this->pod] : [404, ['kind' => 'Status']];
        $this->k8sRoutes['GET /api/v1/namespaces/runr-registry/events'] = fn () => [200, ['items' => $this->events]];
    }

    private function runPvcTest(): array
    {
        return app(ZotRegistryService::class)->testPvcBound($this->kubeconfigYaml(), 'standard', '10Gi', self::NS);
    }

    private static function event(string $type, string $reason, string $message, string $at): array
    {
        return ['type' => $type, 'reason' => $reason, 'message' => $message, 'lastTimestamp' => $at];
    }

    public function test_the_claim_gets_the_configured_seconds_and_the_failure_says_what_it_saw(): void
    {
        config(['knative.registry_pvc_test_seconds' => 10]);
        $this->pod = ['kind' => 'Pod', 'status' => ['phase' => 'Pending', 'containerStatuses' => [
            ['name' => 'pvc-consumer', 'state' => ['waiting' => ['reason' => 'ImagePullBackOff', 'message' => 'Back-off pulling image']]],
        ]]];
        $this->events = [
            self::event('Normal', 'WaitForFirstConsumer', 'waiting for first consumer to be created before binding', '2026-10-23T10:00:00Z'),
            self::event('Warning', 'ProvisioningFailed', 'failed to provision volume with StorageClass "standard": timed out waiting for the helper pod', '2026-10-23T10:00:20Z'),
            self::event('Normal', 'Provisioning', 'External provisioner is provisioning volume for claim "runr-registry/runr-pvc-test"', '2026-10-23T10:00:30Z'),
        ];

        $result = $this->runPvcTest();

        $this->assertFalse($result['success']);
        $this->assertSame(
            "PVC provisioning failed: StorageClass 'standard' did not provision a volume within 10s (PVC Pending, consumer pod Pending (ImagePullBackOff), "
            .'last event ProvisioningFailed: failed to provision volume with StorageClass "standard": timed out waiting for the helper pod)',
            $result['message'],
        );
        // looks at 0, 3, 6, 9 and 10 s, then one for the report
        Sleep::assertSequence([Sleep::for(3)->seconds(), Sleep::for(3)->seconds(), Sleep::for(3)->seconds(), Sleep::for(1)->seconds()]);
        $this->assertCount(6, $this->k8sCallsTo('GET', self::PVCS));
        // the test claim and its consumer pod are removed
        $this->assertCount(1, $this->k8sCallsTo('DELETE', self::PVCS));
        $this->assertCount(1, $this->k8sCallsTo('DELETE', '/api/v1/namespaces/runr-registry/pods/'));
    }

    public function test_the_report_names_the_newest_event_and_why_the_pod_is_not_scheduled(): void
    {
        config(['knative.registry_pvc_test_seconds' => 3]);
        $this->pod = ['kind' => 'Pod', 'status' => ['phase' => 'Pending', 'conditions' => [
            ['type' => 'PodScheduled', 'status' => 'False', 'reason' => 'Unschedulable', 'message' => '0/1 nodes are available'],
        ]]];
        $this->events = [
            self::event('Normal', 'WaitForFirstConsumer', 'waiting for first consumer to be created before binding', '2026-10-23T10:00:00Z'),
        ];

        $this->assertStringEndsWith(
            'within 3s (PVC Pending, consumer pod Pending (Unschedulable), last event WaitForFirstConsumer: waiting for first consumer to be created before binding)',
            $this->runPvcTest()['message'],
        );

        $this->pod = null;
        $this->events = [];
        $this->assertStringEndsWith('within 3s (PVC Pending, consumer pod not found, no event of the PVC)', $this->runPvcTest()['message']);
    }

    public function test_a_claim_that_binds_on_a_later_look_passes(): void
    {
        $this->phases = ['Pending', 'Pending', 'Bound'];

        $this->assertSame(['success' => true, 'message' => 'PVC provisioning successful'], $this->runPvcTest());
        Sleep::assertSleptTimes(2);
        $this->assertSame([], $this->k8sCallsTo('GET', '/api/v1/namespaces/runr-registry/events'));
        $this->assertCount(1, $this->k8sCallsTo('DELETE', self::PVCS));
    }

    public function test_the_default_wait_is_180_seconds(): void
    {
        $this->assertSame(180, config('knative.registry_pvc_test_seconds'));

        $this->assertStringContainsString('did not provision a volume within 180s (PVC Pending, consumer pod not found, no event of the PVC)', $this->runPvcTest()['message']);
        Sleep::assertSleptTimes(60);
    }

    public function test_the_setting_is_kept_between_30_and_900_seconds(): void
    {
        foreach ([['5000', 900], ['5', 30], ['300', 300], [null, 180]] as [$env, $seconds]) {
            if ($env !== null) {
                putenv("RUNR_REGISTRY_PVC_TEST_SECONDS={$env}");
                $_ENV['RUNR_REGISTRY_PVC_TEST_SECONDS'] = $_SERVER['RUNR_REGISTRY_PVC_TEST_SECONDS'] = $env;
            }
            try {
                $this->assertSame($seconds, (require config_path('knative.php'))['registry_pvc_test_seconds'], "RUNR_REGISTRY_PVC_TEST_SECONDS={$env}");
            } finally {
                putenv('RUNR_REGISTRY_PVC_TEST_SECONDS');
                unset($_ENV['RUNR_REGISTRY_PVC_TEST_SECONDS'], $_SERVER['RUNR_REGISTRY_PVC_TEST_SECONDS']);
            }
        }
    }

    public function test_the_registry_setup_jobs_time_out_after_the_wait_and_below_horizons_limits(): void
    {
        $region = $this->obsRegion();
        $jobs = fn () => [new InstallRegistryAndCreateRegionJob('t', []), new InstallRegionRegistryJob($region, [])];

        foreach ($jobs() as $job) {
            $this->assertSame(180 + 720, $job->timeout, $job::class);
        }

        // the longest PVC test: still below every supervisor's timeout and the connection's retry_after
        config(['knative.registry_pvc_test_seconds' => 900]);
        foreach ($jobs() as $job) {
            $this->assertSame(900 + 720, $job->timeout, $job::class);
            $this->assertLessThan(QueuedJobTimeouts::retryAfter('redis'), $job->timeout);
            foreach (QueuedJobTimeouts::supervisors('redis') as $supervisor => $timeout) {
                $this->assertLessThan($timeout, $job->timeout, $job::class.' vs '.$supervisor);
            }
        }
    }
}
