<?php

namespace Tests\Feature\Ai;

use App\Jobs\Ai\CheckModelServiceReadyJob;
use App\Jobs\Ai\DeployModelServiceJob;
use App\Models\AiModelService;
use App\Models\Notification;
use App\Models\User;
use App\Services\Ai\Models\ModelServiceDeployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * A deploying model service is polled (CheckModelServiceReadyJob) until its Knative Service is
 * Ready: ready, failed with Knative's reason, or still starting — its progress message is kept
 * and the poll goes on until the progress deadline. DeployModelServiceJob runs the deploy and
 * marks the service failed when the job itself fails.
 */
class ModelServiceReadinessTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function deploying(array $attributes = []): AiModelService
    {
        $region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig()]);

        return $this->modelService($this->hubModel(), $region, array_merge(['status' => AiModelService::STATUS_DEPLOYING], $attributes));
    }

    /** The service's Knative Service with a Ready condition (null: no condition yet). */
    private function knative(AiModelService $service, ?array $ready): void
    {
        $this->fakeCluster([
            "GET /apis/serving.knative.dev/v1/namespaces/fn/services/{$service->service_name}" => [
                'metadata' => ['name' => $service->service_name],
                'status' => ['conditions' => $ready === null ? [] : [['type' => 'ConfigurationsReady', 'status' => 'True'], ['type' => 'Ready'] + $ready]],
            ],
        ]);
    }

    private function poll(AiModelService $service, int $attempt = 1): void
    {
        (new CheckModelServiceReadyJob($service->id, $attempt))->handle(app(ModelServiceDeployer::class));
    }

    public function test_a_ready_knative_service_makes_the_model_service_ready(): void
    {
        $service = $this->deploying(['status_message' => 'Loading the model']);
        $this->knative($service, ['status' => 'True']);

        $this->poll($service);

        $this->assertSame(AiModelService::STATUS_READY, $service->fresh()->status);
        $this->assertNull($service->fresh()->status_message);
        Queue::assertNotPushed(CheckModelServiceReadyJob::class);
    }

    public function test_a_failed_revision_fails_the_service_with_the_reason(): void
    {
        $service = $this->deploying();
        $this->knative($service, ['status' => 'False', 'reason' => 'RevisionFailed', 'message' => 'Revision "m-svc-00001" failed with message: container exited with code 137 (OOMKilled).']);

        $this->poll($service);

        $this->assertSame(AiModelService::STATUS_FAILED, $service->fresh()->status);
        $this->assertSame('Revision "m-svc-00001" failed with message: container exited with code 137 (OOMKilled).', $service->fresh()->status_message);
        Queue::assertNotPushed(CheckModelServiceReadyJob::class);
    }

    public function test_a_starting_service_keeps_its_progress_message_and_is_polled_again(): void
    {
        $service = $this->deploying();
        $this->knative($service, ['status' => 'Unknown', 'reason' => 'RevisionMissing', 'message' => 'Configuration "m-svc" is waiting for a Revision to become ready.']);

        $this->poll($service, 5);

        $this->assertSame(AiModelService::STATUS_DEPLOYING, $service->fresh()->status);
        $this->assertSame('Configuration "m-svc" is waiting for a Revision to become ready.', $service->fresh()->status_message);
        Queue::assertPushed(CheckModelServiceReadyJob::class, fn ($job) => $job->serviceId === $service->id && $job->attempt === 6);
    }

    public function test_a_service_whose_knative_service_is_not_there_yet_is_polled_again(): void
    {
        $service = $this->deploying(['status_message' => 'Deploy queued.']);
        $this->fakeCluster();   // 404

        $this->poll($service);

        $this->assertSame(AiModelService::STATUS_DEPLOYING, $service->fresh()->status);
        $this->assertSame('Deploy queued.', $service->fresh()->status_message, 'no news: the message stays');
        Queue::assertPushed(CheckModelServiceReadyJob::class, fn ($job) => $job->attempt === 2);
    }

    public function test_the_progress_deadline_fails_a_service_that_never_became_ready(): void
    {
        config(['ai.gpu.progress_deadline' => '10m']);
        $this->assertSame(42, CheckModelServiceReadyJob::maxAttempts(), '600 s / 15 s + 2');

        $service = $this->deploying();
        $this->knative($service, null);
        $this->poll($service, 42);

        $this->assertSame(AiModelService::STATUS_FAILED, $service->fresh()->status);
        $this->assertSame(__('ai.models.not_ready_in_time'), $service->fresh()->status_message);
        Queue::assertNotPushed(CheckModelServiceReadyJob::class);

        config(['ai.gpu.progress_deadline' => '90s']);
        $this->assertSame(8, CheckModelServiceReadyJob::maxAttempts());
        config(['ai.gpu.progress_deadline' => '15s']);
        $this->assertSame(4, CheckModelServiceReadyJob::maxAttempts(), 'never fewer than four checks');
    }

    public function test_a_service_whose_region_lost_its_cluster_fails(): void
    {
        $service = $this->deploying();
        $service->region->update(['kubeconfig' => null]);

        $this->poll($service);

        $this->assertSame(AiModelService::STATUS_FAILED, $service->fresh()->status);
        $this->assertSame(__('ai.models.region_unavailable', ['region' => $service->region->name]), $service->fresh()->status_message);
    }

    public function test_only_deploying_services_are_polled(): void
    {
        $service = $this->deploying(['status' => AiModelService::STATUS_STOPPED]);
        $this->fakeCluster();

        $this->poll($service);

        $this->assertSame([], $this->k8sCalls);
        $this->assertSame(AiModelService::STATUS_STOPPED, $service->fresh()->status);
        Queue::assertNotPushed(CheckModelServiceReadyJob::class);
    }

    public function test_a_failed_deploy_job_fails_the_service_with_its_error(): void
    {
        $service = $this->deploying();
        $user = User::factory()->create();

        (new DeployModelServiceJob($service->id, (string) $user->id))->failed(new \RuntimeException('Timed out after 300 s'));

        $this->assertSame(AiModelService::STATUS_FAILED, $service->fresh()->status);
        $this->assertSame('Timed out after 300 s', $service->fresh()->status_message);
        // the one who deployed it is told
        $notification = Notification::where('user_id', $user->id)->sole();
        $this->assertSame('model_service.deploy_failed', $notification->type);
        $this->assertSame('critical', $notification->level);
        $this->assertSame("Model service {$service->name} failed to deploy", $notification->title);
        $this->assertSame('Timed out after 300 s', $notification->data['error']);

        // a deleted service: nothing to deploy, nothing to mark
        (new DeployModelServiceJob('01missing0000000000000000'))->handle(app(ModelServiceDeployer::class));
        (new DeployModelServiceJob('01missing0000000000000000'))->failed(null);
        $this->assertSame(1, AiModelService::count());
    }
}
