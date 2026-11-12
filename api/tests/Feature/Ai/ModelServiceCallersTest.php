<?php

namespace Tests\Feature\Ai;

use App\Jobs\Ai\SyncModelServiceCallersJob;
use App\Models\AiModelBinding;
use App\Models\AiModelService;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\InternalNetwork\InternalNetworkManifests as M;
use App\Services\InternalNetwork\InternalNetworkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * A model service's callers on the internal network (docs/internal-network.md#model-services-queue-triggers-and-previews)
 * are the projects bound to it: a binding that comes or goes, a bound project renamed or deleted,
 * is written on its Knative Service, which the region's authz reads. A write the region's API
 * server does not take — a refusal, an error, a failed read of the Knative Service — is tried
 * again (SyncModelServiceCallersJob), so a bound project is not refused (403) for good, nor one
 * that is no longer bound — or a later project of a deleted one's name — admitted.
 */
class ModelServiceCallersTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    private KnativeRegion $region;

    private AiModelService $service;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig()]);
        $this->service = $this->modelService($this->hubModel(), $this->region, ['name' => 'embed', 'service_name' => 'm-embed']);
        $this->project = Project::create(['name' => 'chat', 'service_type' => 'caas']);
        $this->project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
    }

    private const KSVC = '/apis/serving.knative.dev/v1/namespaces/fn/services/m-embed';

    /** The callers on the Knative Service in the fake cluster: what the authz admits */
    private string $onCluster = '';

    /** The model service's Knative Service: on the internal network, its callers as last written */
    private function deployed(): array
    {
        return ['metadata' => [
            'name' => 'm-embed', 'namespace' => 'fn',
            'labels' => [AiModelService::LABEL => $this->service->id, M::LABEL_MANAGED => 'managed'],
            'annotations' => [M::ANNOTATION_NAME => 'm-embed.platform', M::ANNOTATION_ORG => 'platform', M::ANNOTATION_CALLERS => $this->onCluster, M::ANNOTATION_KIND => 'model'],
        ]];
    }

    /** A cluster whose API server answers the n-th merge patch of the Knative Service with $answers[n] (then 200) */
    private function cluster(array $answers = [], ?\Closure $read = null): void
    {
        $patches = 0;
        $this->fakeCluster([
            'GET '.self::KSVC => $read ?? fn () => $this->deployed(),
            'PATCH '.self::KSVC => function (Request $request) use (&$patches, $answers) {
                $status = $answers[$patches++] ?? 200;
                if ($status !== 200) {
                    return Http::response(['kind' => 'Status', 'message' => 'Internal error occurred: failed calling webhook "validation.webhook.serving.knative.dev"', 'code' => $status], $status);
                }
                $body = json_decode($request->body(), true);
                $this->onCluster = (string) ($body['metadata']['annotations'][M::ANNOTATION_CALLERS] ?? $this->onCluster);

                return Http::response($body, 200);
            },
        ]);
    }

    /** Whether the region's authz admits this project's pods and token (InternalNetwork::nameOf) */
    private function admitted(Project $project): bool
    {
        return in_array(InternalNetwork::nameOf($project), explode(',', $this->onCluster), true);
    }

    /** @return list<string> the callers annotation of every merge patch of the Knative Service */
    private function writtenCallers(): array
    {
        return array_map(fn ($c) => $c['body']['metadata']['annotations'][M::ANNOTATION_CALLERS] ?? '?', $this->k8sCallsTo('PATCH', self::KSVC));
    }

    private function bind(): void
    {
        $this->postJson("/api/projects/{$this->project->id}/models", ['ai_model_service_id' => $this->service->id, 'alias' => 'EMBED'])->assertCreated();
    }

    public function test_a_binding_is_written_on_the_model_services_knative_service(): void
    {
        $this->cluster();

        $this->bind();

        $this->assertSame(['chat.platform'], $this->writtenCallers());
        $patch = $this->k8sCallsTo('PATCH', self::KSVC)[0];
        $this->assertSame('managed', $patch['body']['metadata']['labels'][M::LABEL_MANAGED]);
        $this->assertSame('model', $patch['body']['metadata']['annotations'][M::ANNOTATION_KIND]);
    }

    /** CI run 43136334516: in-api bound, its pods refused (403) six minutes later — the one write was lost */
    public function test_a_binding_whose_write_the_region_refuses_is_written_again(): void
    {
        $this->cluster([500]);

        $this->bind();

        $this->assertSame(['chat.platform', 'chat.platform'], $this->writtenCallers(), 'written again (the queue runs at once in the tests)');
    }

    public function test_a_failed_read_of_the_knative_service_is_no_missing_one(): void
    {
        $reads = 0;
        $this->cluster([], function () use (&$reads) {
            return $reads++ === 0 ? [['kind' => 'Status', 'code' => 503, 'message' => 'the server is currently unable to handle the request'], 503] : $this->deployed();
        });

        $this->bind();

        $this->assertSame(['chat.platform'], $this->writtenCallers(), 'not taken for a service that is not deployed');
    }

    public function test_an_unbinding_whose_write_the_region_refuses_is_written_again(): void
    {
        $binding = AiModelBinding::create(['project_id' => $this->project->id, 'ai_model_service_id' => $this->service->id, 'alias' => 'EMBED']);
        $this->cluster([502]);

        $this->deleteJson("/api/projects/{$this->project->id}/models/{$binding->id}")->assertNoContent();

        $this->assertSame(['', ''], $this->writtenCallers(), 'the unbound project is not admitted on');
    }

    public function test_a_lasting_refusal_is_tried_again_by_the_queue_until_it_gives_up(): void
    {
        Queue::fake();
        $this->cluster([500, 500]);

        $this->bind();

        $this->assertCount(1, $this->k8sCallsTo('PATCH', self::KSVC), 'one write in the request');
        Queue::assertPushed(SyncModelServiceCallersJob::class, fn (SyncModelServiceCallersJob $job) => $job->serviceId === $this->service->id && $job->delay !== null);

        // a try that does not reach the region goes back on the queue for its backoff (never an
        // exception into a sync dispatch: the binding answered 201 all the same)
        $job = (new SyncModelServiceCallersJob($this->service->id))->withFakeQueueInteractions();
        $job->handle(app(InternalNetworkService::class));
        $job->assertReleased(delay: $job->backoff()[0]);
        $this->assertFalse($this->admitted($this->project));
        $this->assertGreaterThan(1, $job->tries);
        $this->assertGreaterThanOrEqual(5 * 60, array_sum($job->backoff()), 'minutes, not seconds: a region that is back after a restart');

        Log::spy();
        $job->failed(new \RuntimeException('refused'));
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => str_contains($message, 'region:reconcile'));

        // the region takes it on the next try
        $next = (new SyncModelServiceCallersJob($this->service->id))->withFakeQueueInteractions();
        $next->handle(app(InternalNetworkService::class));
        $next->assertNotReleased();
        $this->assertSame(['chat.platform', 'chat.platform', 'chat.platform'], $this->writtenCallers());
        $this->assertTrue($this->admitted($this->project));
    }

    public function test_a_service_without_a_knative_service_waits_for_its_deploy(): void
    {
        Queue::fake();
        $this->service->update(['status' => AiModelService::STATUS_STOPPED]);
        $this->cluster([], fn () => [['kind' => 'Status', 'code' => 404, 'reason' => 'NotFound'], 404]);

        $this->bind();

        $this->assertSame([], $this->writtenCallers(), 'its deploy writes the callers (ModelServiceManifests)');
        Queue::assertNotPushed(SyncModelServiceCallersJob::class);
    }

    /** Its bindings go with its row (a cascade): the model service drops its name, no later project gets it */
    public function test_a_deleted_bound_project_leaves_the_model_services_callers(): void
    {
        $this->cluster();
        $this->bind();
        $this->assertTrue($this->admitted($this->project));

        $this->project->delete();

        $this->assertSame('', $this->onCluster);
        $this->assertSame(0, AiModelBinding::query()->count());
        $reborn = Project::create(['name' => 'chat', 'service_type' => 'caas']);
        $reborn->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        $this->assertSame('chat.platform', InternalNetwork::nameOf($reborn), 'the deleted project\'s name');
        $this->assertFalse($this->admitted($reborn), 'a new project of the same name is not bound');
    }

    public function test_a_deleted_bound_project_leaves_the_callers_though_the_first_write_fails(): void
    {
        $this->cluster();
        $this->bind();
        $this->cluster([503]);

        $this->project->delete();

        $this->assertSame('', $this->onCluster, 'written again (the queue runs at once in the tests)');
    }

    /** A new name is a new internal name: the bound project keeps its access, its former name loses it */
    public function test_a_renamed_bound_project_is_its_new_name_on_the_model_service(): void
    {
        $this->cluster();
        $this->bind();

        $this->project->update(['name' => 'talk']);

        $this->assertSame('talk.platform', $this->onCluster);
        $this->assertTrue($this->admitted($this->project->fresh()));
        $other = Project::create(['name' => 'chat', 'service_type' => 'caas']);
        $this->assertFalse($this->admitted($other), 'its former name is not admitted');
    }
}
