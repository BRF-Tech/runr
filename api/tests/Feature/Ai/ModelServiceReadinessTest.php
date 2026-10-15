<?php

namespace Tests\Feature\Ai;

use App\Http\Resources\Ai\AiModelServiceResource;
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
 * and the poll goes on until the progress deadline. Meanwhile pods Kubernetes cannot start give
 * the reason (CrashLoopBackOff with the engine's output, ImagePullBackOff, ErrImagePull,
 * OOMKilled, Unschedulable …). DeployModelServiceJob runs the deploy and marks the service
 * failed when the job itself fails.
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

    private array $podStatuses = [];

    private array $readyCondition = [];

    private bool $clusterFaked = false;

    /** The Knative Service (any name) has this Ready condition and its pods these statuses, until the next call. */
    private function starting(AiModelService $service, array $podStatuses, array $ready = ['status' => 'Unknown', 'reason' => 'RevisionMissing', 'message' => 'Configuration "m-svc" is waiting for a Revision to become ready.']): void
    {
        $this->podStatuses = $podStatuses;
        $this->readyCondition = $ready;
        if ($this->clusterFaked) {
            return;
        }
        $this->clusterFaked = true;

        $this->fakeCluster([
            'GET /apis/serving.knative.dev/v1/namespaces/fn/services/*' => fn () => [
                'status' => ['conditions' => [['type' => 'Ready'] + $this->readyCondition]],
            ],
            'GET /api/v1/namespaces/fn/pods' => fn () => ['items' => array_map(fn (array $status, int $i) => [
                'metadata' => ['name' => "{$service->service_name}-00001-deployment-{$i}"],
                'status' => $status,
            ], $this->podStatuses, array_keys($this->podStatuses))],
        ]);
    }

    /** TEI 1.5 against today's Hub: it exits on the first download and Kubernetes backs off. */
    private function teiCrashLoop(): array
    {
        return [
            'phase' => 'Running',
            'conditions' => [['type' => 'PodScheduled', 'status' => 'True'], ['type' => 'Ready', 'status' => 'False']],
            'initContainerStatuses' => [['name' => 'models-init', 'ready' => true, 'restartCount' => 0, 'state' => ['terminated' => ['exitCode' => 0, 'reason' => 'Completed']]]],
            'containerStatuses' => [
                [
                    'name' => 'model', 'ready' => false, 'restartCount' => 4,
                    'state' => ['waiting' => ['reason' => 'CrashLoopBackOff', 'message' => 'back-off 1m20s restarting failed container=model pod=m-svc-00001-deployment-0']],
                    'lastState' => ['terminated' => [
                        'exitCode' => 1, 'reason' => 'Error',
                        'message' => "\e[2m2026-10-14T09:12:01Z\e[0m \e[32m INFO\e[0m download_artifacts: Downloading `config.json`\nError: Could not download model artifacts\n\nCaused by:\n    0: request error: builder error: relative URL without a base\n",
                    ]],
                ],
                ['name' => 'queue-proxy', 'ready' => false, 'restartCount' => 0, 'state' => ['running' => ['startedAt' => '2026-10-14T09:10:00Z']]],
            ],
        ];
    }

    public function test_a_crash_looping_engine_shows_why_while_the_service_deploys(): void
    {
        $service = $this->deploying(['status_message' => 'Deploy queued.']);
        $this->starting($service, [$this->teiCrashLoop()]);

        $this->poll($service, 3);

        $service->refresh();
        $this->assertSame(AiModelService::STATUS_DEPLOYING, $service->status, 'Kubernetes keeps retrying: the deadline decides');
        $this->assertSame('CrashLoopBackOff', $service->status_reason);
        $this->assertSame(
            "CrashLoopBackOff: the model container keeps exiting (4 restarts, last exit code 1, Error).\n"
            ."2026-10-14T09:12:01Z  INFO download_artifacts: Downloading `config.json`\nError: Could not download model artifacts\n\nCaused by:\n    0: request error: builder error: relative URL without a base",
            $service->status_message,
            'the engine output (its log tail as termination message) without colour codes',
        );
        Queue::assertPushed(CheckModelServiceReadyJob::class, fn ($job) => $job->attempt === 4);

        $json = (new AiModelServiceResource($service))->toArray(request());
        $this->assertSame(['deploying', 'CrashLoopBackOff', $service->status_message], [$json['status'], $json['status_reason'], $json['status_message']]);
    }

    public function test_image_pull_problems_show_the_image_and_the_registry_answer(): void
    {
        $pulling = fn (string $reason, string $message) => [
            'phase' => 'Pending',
            'containerStatuses' => [[
                'name' => 'model', 'ready' => false, 'restartCount' => 0,
                'state' => ['waiting' => ['reason' => $reason, 'message' => $message]],
            ]],
        ];

        $service = $this->deploying();
        $this->starting($service, [$pulling('ErrImagePull', 'failed to pull and unpack image "ghcr.io/huggingface/text-embeddings-inference:cpu-9.9": not found')]);
        $this->poll($service);

        $this->assertSame('ErrImagePull', $service->fresh()->status_reason);
        $this->assertSame('ErrImagePull: the image of the model container cannot be pulled: failed to pull and unpack image "ghcr.io/huggingface/text-embeddings-inference:cpu-9.9": not found', $service->fresh()->status_message);

        $this->starting($service, [$pulling('ImagePullBackOff', 'Back-off pulling image "ghcr.io/huggingface/text-embeddings-inference:cpu-9.9"')]);
        $this->poll($service, 2);

        $this->assertSame(AiModelService::STATUS_DEPLOYING, $service->fresh()->status);
        $this->assertSame('ImagePullBackOff', $service->fresh()->status_reason);
        $this->assertStringContainsString('Back-off pulling image', $service->fresh()->status_message);

        // a missing Secret / ConfigMap: the container cannot be created
        $this->starting($service, [$pulling('CreateContainerConfigError', '')]);
        $this->poll($service, 3);
        $this->assertSame('CreateContainerConfigError: the model container cannot start: CreateContainerConfigError', $service->fresh()->status_message);
    }

    public function test_an_engine_killed_at_its_memory_limit_asks_for_more_memory(): void
    {
        $service = $this->deploying(['memory_mb' => 1024]);
        $this->starting($service, [[
            'phase' => 'Running',
            'containerStatuses' => [[
                'name' => 'model', 'ready' => false, 'restartCount' => 1,
                'state' => ['running' => ['startedAt' => '2026-10-14T09:12:00Z']],
                'lastState' => ['terminated' => ['exitCode' => 137, 'reason' => 'OOMKilled']],
            ]],
        ]]);

        $this->poll($service);

        $this->assertSame('OOMKilled', $service->fresh()->status_reason);
        $this->assertSame('OOMKilled: the model container keeps exiting (1 restarts, last exit code 137, OOMKilled). It was stopped at the memory limit of 1024 MiB: give the service more memory.', $service->fresh()->status_message);
    }

    public function test_a_pod_no_node_can_take_says_why(): void
    {
        $service = $this->deploying();
        $this->starting($service, [[
            'phase' => 'Pending',
            'conditions' => [['type' => 'PodScheduled', 'status' => 'False', 'reason' => 'Unschedulable', 'message' => '0/3 nodes are available: 3 Insufficient memory.']],
        ]]);

        $this->poll($service);

        $this->assertSame('Unschedulable', $service->fresh()->status_reason);
        $this->assertSame('No node can take the pod: 0/3 nodes are available: 3 Insufficient memory.', $service->fresh()->status_message);
    }

    public function test_starting_pods_have_no_reason_and_a_problem_that_is_gone_takes_its_message(): void
    {
        $service = $this->deploying(['status_reason' => 'ImagePullBackOff', 'status_message' => 'ImagePullBackOff: …']);
        $this->starting($service, [[
            'phase' => 'Pending',
            'containerStatuses' => [['name' => 'model', 'ready' => false, 'restartCount' => 0, 'state' => ['waiting' => ['reason' => 'ContainerCreating']]]],
        ]], ['status' => 'Unknown']);

        $this->poll($service);

        $this->assertNull($service->fresh()->status_reason);
        $this->assertNull($service->fresh()->status_message, 'the pull went through: no stale problem');
        $this->assertSame(AiModelService::STATUS_DEPLOYING, $service->fresh()->status);
    }

    public function test_the_deadline_and_a_failed_revision_keep_the_pods_reason(): void
    {
        config(['ai.gpu.progress_deadline' => '10m']);
        $service = $this->deploying();
        $this->starting($service, [$this->teiCrashLoop()]);

        $this->poll($service, CheckModelServiceReadyJob::maxAttempts());

        $this->assertSame(AiModelService::STATUS_FAILED, $service->fresh()->status);
        $this->assertSame('CrashLoopBackOff', $service->fresh()->status_reason);
        $this->assertStringContainsString('relative URL without a base', $service->fresh()->status_message);

        // Knative gave up on the revision: its message, the pods' reason
        $other = $this->modelService($service->model, $service->region, ['status' => AiModelService::STATUS_DEPLOYING]);
        $this->starting($other, [$this->teiCrashLoop()], ['status' => 'False', 'reason' => 'RevisionFailed', 'message' => 'Revision "m-svc-00001" failed with message: Container failed with: Error: Could not download model artifacts.']);
        $this->poll($other);

        $this->assertSame(AiModelService::STATUS_FAILED, $other->fresh()->status);
        $this->assertSame('CrashLoopBackOff', $other->fresh()->status_reason);
        $this->assertSame('Revision "m-svc-00001" failed with message: Container failed with: Error: Could not download model artifacts.', $other->fresh()->status_message);
    }

    public function test_a_new_status_starts_without_the_old_reason(): void
    {
        $service = $this->deploying(['status_reason' => 'CrashLoopBackOff', 'status_message' => 'CrashLoopBackOff: …']);
        $this->knative($service, ['status' => 'True']);

        $this->poll($service);
        $this->assertNull($service->fresh()->status_reason, 'ready');

        $service->update(['status' => AiModelService::STATUS_DEPLOYING, 'status_reason' => 'ErrImagePull']);
        $this->assertSame('ErrImagePull', $service->fresh()->status_reason, 'set with the status');
        $service->update(['status' => AiModelService::STATUS_STOPPED]);
        $this->assertNull($service->fresh()->status_reason, 'stopped');
    }

    public function test_long_messages_keep_their_end(): void
    {
        $status = $this->teiCrashLoop();
        $status['containerStatuses'][0]['lastState']['terminated']['message'] = str_repeat('x', 3000).' the end';

        $problem = ModelServiceDeployer::podProblem([['status' => $status]]);

        $this->assertStringEndsWith('x the end', $problem['message']);
        $this->assertStringContainsString("\n…x", $problem['message'], 'the last 1500 characters');
        $this->assertNull(ModelServiceDeployer::podProblem([]));
        $this->assertNull(ModelServiceDeployer::podProblem([['status' => ['phase' => 'Running', 'containerStatuses' => [['name' => 'model', 'ready' => true, 'state' => ['running' => []]]]]]]));
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
