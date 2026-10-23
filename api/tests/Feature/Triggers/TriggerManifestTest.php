<?php

namespace Tests\Feature\Triggers;

use App\Models\Deployment;
use App\Models\Topic;
use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\ResourceReservation;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\KnativeDeployService;
use App\Triggers\Inbox\InboxToken;
use App\Triggers\ProjectTriggers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTriggerFixtures;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * What a project's trigger list deploys (App\Triggers\TriggerPlan, docs/triggers.md): CronJob or
 * Knative Service, public or cluster-local, and the event gateway in its env-only (single queue)
 * or gateway.json form.
 */
class TriggerManifestTest extends TestCase
{
    use BuildsTriggerFixtures;
    use FakesRegionSecrets;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function build($project): array
    {
        return app(KnativeDeployService::class)->buildManifests($project->fresh(), $this->triggerVersion($project));
    }

    private function gatewayJson(array $manifests, string $slug): array
    {
        $secret = $this->manifestNamed($manifests, 'Secret', "{$slug}-qc-config");
        $this->assertNotNull($secret, 'gateway.json Secret');

        return json_decode(base64_decode($secret['data']['gateway.json']), true);
    }

    public function test_a_new_project_gets_its_primary_trigger_from_the_config_row(): void
    {
        $project = $this->triggerProject($this->triggerRegion(), 'queue');

        $rows = $project->triggers()->get();
        $this->assertCount(1, $rows);
        $this->assertSame('queue', $rows[0]->type);
        $this->assertSame(['stream' => 'orders'], $rows[0]->config);
        $this->assertSame(0, $rows[0]->position);
    }

    public function test_an_http_project_runs_a_public_service_without_a_gateway(): void
    {
        $project = $this->triggerProject($this->triggerRegion());
        $manifests = $this->build($project);

        $service = collect($manifests)->firstWhere('kind', 'Service');
        $this->assertArrayNotHasKey('networking.knative.dev/visibility', $service['metadata']['labels']);
        $this->assertNull($this->manifestNamed($manifests, 'Deployment', "{$project->slug}-qc"));
    }

    public function test_a_single_queue_trigger_keeps_the_env_only_consumer(): void
    {
        $project = $this->triggerProject($this->triggerRegion(), 'queue');
        $manifests = $this->build($project);

        $consumer = $this->manifestNamed($manifests, 'Deployment', "{$project->slug}-qc");
        $env = collect($consumer['spec']['template']['spec']['containers'][0]['env']);
        $this->assertSame('orders', $env->firstWhere('name', 'STREAM')['value']);
        $this->assertSame($project->slug, $env->firstWhere('name', 'GROUP')['value']);
        $this->assertNull($env->firstWhere('name', 'GATEWAY_CONFIG'));
        $this->assertNotNull($this->manifestNamed($manifests, 'Secret', "{$project->slug}-qc-redis"));
        $this->assertNull($this->manifestNamed($manifests, 'Secret', "{$project->slug}-qc-config"));

        $service = collect($manifests)->first(fn ($m) => $m['kind'] === 'Service' && str_starts_with($m['apiVersion'], 'serving.knative.dev/'));
        $this->assertSame('cluster-local', $service['metadata']['labels']['networking.knative.dev/visibility']);
    }

    public function test_http_and_webhook_run_a_public_service_and_the_gateway_from_its_config_secret(): void
    {
        $project = $this->triggerProject($this->triggerRegion());
        $webhook = $this->addTrigger($project, 'webhook', ['verify' => 'github'], ['secrets' => ['signing_secret' => 'gh-signing-secret']]);
        $manifests = $this->build($project);

        $service = collect($manifests)->first(fn ($m) => $m['kind'] === 'Service' && str_starts_with($m['apiVersion'], 'serving.knative.dev/'));
        $this->assertArrayNotHasKey('networking.knative.dev/visibility', $service['metadata']['labels'], 'the HTTP trigger keeps the service public');

        $gateway = $this->manifestNamed($manifests, 'Deployment', "{$project->slug}-qc");
        $container = $gateway['spec']['template']['spec']['containers'][0];
        $this->assertSame([
            ['name' => 'GATEWAY_CONFIG', 'value' => '/etc/runr/gateway/gateway.json'],
            // the gateway calls the function on the internal network with the project's token
            ['name' => InternalNetwork::ENV_TOKEN, 'valueFrom' => ['secretKeyRef' => ['name' => "{$project->slug}-secret-env", 'key' => InternalNetwork::ENV_TOKEN]]],
        ], $container['env']);
        $this->assertSame("{$project->slug}-qc-config", $gateway['spec']['template']['spec']['volumes'][0]['secret']['secretName']);
        $this->assertTrue($container['volumeMounts'][0]['readOnly']);
        $this->assertNotNull($this->manifestNamed($manifests, 'Service', "{$project->slug}-qc"), 'the nudge Service');

        $config = $this->gatewayJson($manifests, $project->slug);
        $this->assertSame("http://{$project->slug}.ev.svc.cluster.local", $config['sink']);
        $this->assertCount(1, $config['triggers']);
        $entry = $config['triggers'][0];
        $this->assertSame(['webhook', 'webhook', 'inbox'], [$entry['name'], $entry['type'], $entry['kind']]);
        $this->assertSame(4, $entry['concurrency']);
        $this->assertSame(5, $entry['max_retries']);
        $this->assertStringEndsWith('/api/internal/triggers/central/'.$project->id.'/'.InboxToken::for('central', $project->id), $entry['source']['url']);
        $this->assertSame('webhook', $entry['source']['trigger']);

        // The signing secret stays in the API: neither the Deployment nor gateway.json carry it
        $this->assertStringNotContainsString('gh-signing-secret', json_encode($manifests));
        $this->assertNotNull($webhook->fresh()->ingress_key);

        // the Secret is applied before the Deployment that mounts it
        $this->assertLessThan(
            array_search($gateway, $manifests, true),
            array_search($this->manifestNamed($manifests, 'Secret', "{$project->slug}-qc-config"), $manifests, true),
        );
    }

    public function test_a_schedule_alone_is_a_cronjob_and_next_to_http_a_gateway_source(): void
    {
        $region = $this->triggerRegion();
        $alone = $this->triggerProject($region, 'schedule', 'nightly');
        $manifests = $this->build($alone);
        $this->assertNotNull($this->manifestNamed($manifests, 'CronJob', 'nightly-trigger'));
        $this->assertNull(collect($manifests)->first(fn ($m) => $m['kind'] === 'Service'));

        $combined = $this->triggerProject($region, 'http', 'api-and-cron');
        $this->addTrigger($combined, 'schedule', ['cron' => '0 3 * * *'], ['name' => 'nightly']);
        $manifests = $this->build($combined);

        $this->assertNull(collect($manifests)->firstWhere('kind', 'CronJob'), 'no CronJob pod next to a service');
        $entry = $this->gatewayJson($manifests, $combined->slug)['triggers'][0];
        $this->assertSame(['nightly', 'schedule', 'schedule'], [$entry['name'], $entry['type'], $entry['kind']]);
        // without a zone of its own the schedule runs in (and follows) the organization's
        $this->assertSame(['cron' => '0 3 * * *', 'timezone' => 'Europe/Istanbul', 'timezone_from' => 'organization'], $entry['source']);
    }

    public function test_a_queue_with_its_own_delivery_settings_and_a_topic_subscription(): void
    {
        $region = $this->triggerRegion();
        Topic::query()->create(['name' => 'orders.created', 'knative_region_id' => $region->id]);
        $project = $this->triggerProject($region, 'queue', 'billing');
        $queue = $project->triggers()->first();
        ProjectTriggers::withoutSync(fn () => $queue->forceFill(['config' => ['stream' => 'orders', 'delivery' => ['concurrency' => 8, 'max_retries' => 2]]])->save());
        $this->addTrigger($project, 'topic', ['topic' => 'orders.created', 'start_from' => 'latest'], ['name' => 'created']);

        $config = $this->gatewayJson($this->build($project), 'billing');
        [$q, $t] = $config['triggers'];

        $this->assertSame('redis-stream', $q['kind']);
        $this->assertSame(['stream' => 'orders', 'group' => 'billing', 'start' => '0', 'format' => 'queue'], array_intersect_key($q['source'], array_flip(['stream', 'group', 'start', 'format'])));
        $this->assertSame('redis://:redis-pw@redis.ev.svc:6379', $q['source']['address']);
        $this->assertSame([8, 2], [$q['concurrency'], $q['max_retries']]);
        $this->assertSame('orders-dlq', $q['dead_letter']['stream']);

        $this->assertSame('runr.topic.orders.created', $t['source']['stream']);
        $this->assertSame('sub.billing.created', $t['source']['group']);
        $this->assertSame('$', $t['source']['start']);
        $this->assertSame('topic', $t['source']['format']);
    }

    public function test_an_event_only_project_is_cluster_local_and_runs_in_queue_mode(): void
    {
        $project = $this->triggerProject($this->triggerRegion(), 'http', 'hooks-only');
        $http = $project->triggers()->first();
        ProjectTriggers::withoutSync(fn () => $http->forceFill(['enabled' => false])->save());
        $this->addTrigger($project, 'platform_event', ['events' => ['deployment.failed']]);

        $plan = app(ProjectTriggers::class)->plan($project->fresh());
        $this->assertFalse($plan->public());
        $this->assertSame('queue', $plan->runtimeTrigger());
        $this->assertSame('queue', $plan->capacityTrigger());

        $manifests = $this->build($project);
        $service = collect($manifests)->first(fn ($m) => $m['kind'] === 'Service' && str_starts_with($m['apiVersion'], 'serving.knative.dev/'));
        $this->assertSame('cluster-local', $service['metadata']['labels']['networking.knative.dev/visibility']);
    }

    public function test_the_gateway_counts_in_the_project_reservation(): void
    {
        $project = $this->triggerProject($this->triggerRegion());
        $before = ResourceReservation::forProject($project->fresh());

        $this->addTrigger($project, 'webhook', ['verify' => 'none']);
        $after = ResourceReservation::forProject($project->fresh());

        $this->assertSame($before['cpu_m'] + KnativeConstants::QUEUE_CONSUMER_CPU_M, $after['cpu_m']);
        $this->assertSame($before['memory_mb'] + KnativeConstants::QUEUE_CONSUMER_MEMORY_MB, $after['memory_mb']);
    }

    public function test_both_gateway_forms_call_the_function_as_the_project_on_the_internal_network(): void
    {
        $region = $this->triggerRegion();
        $queue = $this->triggerProject($region, 'queue');
        $webhook = $this->triggerProject($region, 'http', 'hooks-fn');
        $this->addTrigger($webhook, 'webhook', ['verify' => 'none']);

        foreach ([$queue, $webhook] as $project) {
            $gateway = $this->manifestNamed($this->build($project), 'Deployment', "{$project->slug}-qc");
            $env = collect($gateway['spec']['template']['spec']['containers'][0]['env']);
            // RUNR_INTERNAL_TOKEN of the project's Secret (sent in X-Runr-Internal-Token), no other signature
            $this->assertSame(
                ['name' => "{$project->slug}-secret-env", 'key' => InternalNetwork::ENV_TOKEN],
                $env->firstWhere('name', InternalNetwork::ENV_TOKEN)['valueFrom']['secretKeyRef'] ?? null,
                $project->slug,
            );
            $this->assertNull($env->firstWhere('name', 'RUNR_INTERNAL_SIGNING_KEY'));
            // the authz knows its pods by their address as the project's
            $labels = $gateway['spec']['template']['metadata']['labels'];
            $this->assertSame((string) $project->id, $labels['runr/project-id']);
            $this->assertSame(InternalNetwork::nameOf($project->fresh()), $labels[InternalNetwork::POD_LABEL] ?? null);
        }
    }

    public function test_functions_get_the_topic_publisher_when_their_organization_has_a_topic_in_the_region(): void
    {
        $region = $this->triggerRegion();
        $project = $this->triggerProject($region);
        $env = collect(collect($this->build($project))->firstWhere('kind', 'Service')['spec']['template']['spec']['containers'][0]['env'] ?? []);
        $this->assertNull($env->firstWhere('name', 'RUNR_TOPICS_URL'));

        Topic::query()->create(['name' => 'orders', 'knative_region_id' => $region->id]);
        $manifests = app(KnativeDeployService::class)->buildManifests($project->fresh(), $this->triggerVersion($project, 2));
        $env = collect(collect($manifests)->first(fn ($m) => $m['kind'] === 'Service' && str_starts_with($m['apiVersion'], 'serving.knative.dev/'))['spec']['template']['spec']['containers'][0]['env']);

        $this->assertSame('http://runr-topics.ev.svc.cluster.local:8080', $env->firstWhere('name', 'RUNR_TOPICS_URL')['value']);
        $this->assertSame(['secretKeyRef' => ['name' => "{$project->slug}-secret-env", 'key' => 'RUNR_TOPICS_TOKEN']], $env->firstWhere('name', 'RUNR_TOPICS_TOKEN')['valueFrom']);
        // RunR's own key: written into the region's values Secret by the deploy, never in a manifest
        $this->assertNull($this->manifestNamed($manifests, 'Secret', "{$project->slug}-secret-env"));
        $this->fakeRegionSecrets();
        app(KnativeDeployService::class)->prepareSecrets($project->fresh());
        $this->assertStringStartsWith('v1.', (string) ($this->regionSecrets->values($region, "{$project->slug}-secret-env")['RUNR_TOPICS_TOKEN'] ?? ''));
    }

    public function test_teardown_removes_both_gateway_forms(): void
    {
        $project = $this->triggerProject($this->triggerRegion());
        $version = $this->triggerVersion($project);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active']);

        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $calls[] = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);

            return Http::response(['kind' => 'Status'], 200);
        });

        app(KnativeDeployService::class)->teardown($project, $deployment);

        foreach ([
            "DELETE /apis/apps/v1/namespaces/ev/deployments/{$project->slug}-qc",
            "DELETE /api/v1/namespaces/ev/secrets/{$project->slug}-qc-redis",
            "DELETE /api/v1/namespaces/ev/secrets/{$project->slug}-qc-config",
            "DELETE /api/v1/namespaces/ev/services/{$project->slug}-qc",
        ] as $call) {
            $this->assertContains($call, $calls);
        }
    }
}
