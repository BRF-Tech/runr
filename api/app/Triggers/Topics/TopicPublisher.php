<?php

namespace App\Triggers\Topics;

use App\Models\KnativeRegion;
use App\Models\Topic;
use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\DedicatedWorkers;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The topic publisher of a region (docs/triggers.md#topics): Deployment + Service `runr-topics`
 * in the region's project namespace, the event gateway image in GATEWAY_MODE=publisher. It XADDs
 * a published event to the topic's stream on the region's Redis after checking the publish token
 * (TopicTokens): functions call it at RUNR_TOPICS_URL with their RUNR_TOPICS_TOKEN; the RunR API
 * reaches it through the region's Kubernetes API service proxy (publish(), delete()).
 *
 * One publisher serves every organization of the region: a token only reaches the streams of the
 * organization whose suffix it carries.
 *
 * Capacity: the publisher (50m CPU, 64 MiB, requests = limits) is a region component, not a
 * project's workload — no runr/project-id label — so the region status check subtracts its requests
 * from the region capacity like those of every other platform pod (KnativeNodeService), as for
 * gateway-auth and the internal-network authz. Its placement tolerates dedicated workers.
 */
class TopicPublisher
{
    public const NAME = 'runr-topics';

    public const PORT = 8080;

    /**
     * The header RunR's own calls carry their token in: they go through the Kubernetes API
     * server's service proxy, which authenticates with the Authorization header (a region's
     * bearer-token kubeconfig) and drops it, so the publisher never saw a bearer token there.
     * Functions call the publisher directly with Authorization: Bearer.
     */
    public const TOKEN_HEADER = 'X-Runr-Topic-Token';

    /** In-cluster URL functions publish to. */
    public static function url(KnativeRegion $region): string
    {
        return 'http://'.self::NAME.'.'.self::namespace($region).'.svc.cluster.local:'.self::PORT;
    }

    public static function namespace(KnativeRegion $region): string
    {
        return $region->project_namespace ?? $region->name;
    }

    /**
     * Apply the publisher's Secret, Deployment and Service (idempotent; skipped for 10 minutes
     * after an apply of the same manifests).
     *
     * @throws RuntimeException the region has no Redis / no kubeconfig, or an apply failed
     */
    public function ensure(KnativeRegion $region): void
    {
        if ($region->is_demo) {
            return;
        }
        if (! $region->has_redis || ! $region->kubeconfig) {
            throw new RuntimeException(__('triggers.errors.topics_need_redis'));
        }

        $manifests = $this->manifests($region);
        $marker = 'triggers:topic-publisher:'.$region->getKey().':'.substr(hash('sha256', (string) json_encode($manifests)), 0, 16);
        if (Cache::get($marker)) {
            return;
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        foreach ($manifests as $manifest) {
            if (! $k8s->applyResource($manifest, self::namespace($region))) {
                throw new RuntimeException("Failed to apply {$manifest['kind']} ".self::NAME);
            }
        }

        Cache::put($marker, true, now()->addMinutes(10));
    }

    /**
     * Publish one event to a topic through the region's publisher. Returns the publisher's answer
     * ({id, stream_id}).
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException the publisher did not take the event
     */
    public function publish(Topic $topic, string $data, string $contentType = 'application/json', ?string $type = null, ?string $subject = null, ?string $id = null): array
    {
        $region = $topic->knativeRegion ?? throw new RuntimeException(__('triggers.errors.topics_need_redis'));
        if (strlen($data) > (int) config('triggers.topic_max_bytes', 262144)) {
            throw ValidationException::withMessages(['data' => __('triggers.errors.event_too_large')]);
        }

        // A demo region has no cluster and no Redis: the event is taken as the console shows it
        // (its other workloads are simulated the same way), nothing is delivered
        if ($region->is_demo) {
            return ['id' => $id !== null && $id !== '' ? $id : (string) Str::ulid(), 'stream_id' => now()->getTimestampMs().'-0', 'demo' => true];
        }
        if (! $region->kubeconfig) {
            throw new RuntimeException(__('triggers.errors.topics_need_redis'));
        }

        $this->ensure($region);

        $headers = array_filter([
            self::TOKEN_HEADER => TopicTokens::forApi($region, $topic->name),
            'Content-Type' => $contentType,
            'ce-type' => $type,
            'ce-subject' => $subject,
            'ce-id' => $id,
        ], fn ($v) => $v !== null && $v !== '');

        $response = (new KubernetesClient($region->kubeconfig))->serviceProxySend(
            'POST', self::namespace($region), self::NAME, self::PORT,
            'v1/topics/'.rawurlencode($topic->name).'/events', $headers, $data, 15,
        );

        if (! $response->successful()) {
            throw new RuntimeException(__('triggers.errors.publish_failed', ['status' => $response->status(), 'message' => mb_substr((string) $response->body(), 0, 200)]));
        }

        return (array) $response->json();
    }

    /** Delete the topic's stream (its subscribers' groups go with it). Best effort. */
    public function delete(Topic $topic): bool
    {
        $region = $topic->knativeRegion;
        if (! $region || $region->is_demo || ! $region->kubeconfig || ! $region->has_redis) {
            return true;
        }

        try {
            $response = (new KubernetesClient($region->kubeconfig))->serviceProxySend(
                'DELETE', self::namespace($region), self::NAME, self::PORT,
                'v1/topics/'.rawurlencode($topic->name),
                [self::TOKEN_HEADER => TopicTokens::forApi($region, $topic->name, admin: true)],
                null, 15,
            );

            return $response->successful() || $response->status() === 404;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Secret, Deployment and Service of the publisher. The Redis address / password and the
     * signing key reach the container from the Secret only (docs/security.md).
     *
     * @return list<array>
     */
    public function manifests(KnativeRegion $region): array
    {
        $namespace = self::namespace($region);
        $labels = ['app.kubernetes.io/managed-by' => 'runr', 'runr/component' => 'topic-publisher'];
        $raw = (string) ($region->redis_address ?? 'localhost:6379');
        $scheme = str_starts_with($raw, 'rediss://') ? 'rediss' : 'redis';
        $host = preg_replace('#^rediss?://#', '', $raw);
        $secretRef = fn (string $key) => ['secretKeyRef' => ['name' => self::NAME, 'key' => $key]];

        $data = [
            'REDIS_ADDRESS' => base64_encode("{$scheme}://{$host}"),
            'TOPICS_SIGNING_KEY' => base64_encode(TopicTokens::key($region)),
        ];
        $env = [
            ['name' => 'GATEWAY_MODE', 'value' => 'publisher'],
            ['name' => 'REDIS_ADDRESS', 'valueFrom' => $secretRef('REDIS_ADDRESS')],
            ['name' => 'TOPICS_SIGNING_KEY', 'valueFrom' => $secretRef('TOPICS_SIGNING_KEY')],
            ['name' => 'TOPIC_MAXLEN', 'value' => (string) (int) config('triggers.topic_max_len', 10000)],
            ['name' => 'PUBLISH_MAX_BYTES', 'value' => (string) (int) config('triggers.topic_max_bytes', 262144)],
        ];
        if ($region->redis_password) {
            $data['REDIS_PASSWORD'] = base64_encode((string) $region->redis_password);
            $env[] = ['name' => 'REDIS_PASSWORD', 'valueFrom' => $secretRef('REDIS_PASSWORD')];
        }

        $size = ['cpu' => KnativeConstants::QUEUE_CONSUMER_CPU_M.'m', 'memory' => KnativeConstants::QUEUE_CONSUMER_MEMORY_MB.'Mi'];

        return [
            [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => ['name' => self::NAME, 'namespace' => $namespace, 'labels' => $labels],
                'type' => 'Opaque',
                'data' => $data,
            ],
            [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => ['name' => self::NAME, 'namespace' => $namespace, 'labels' => $labels],
                'spec' => [
                    'replicas' => 1,
                    'selector' => ['matchLabels' => $labels],
                    'template' => [
                        'metadata' => ['labels' => $labels],
                        // A region component like gateway-auth or the internal authz: its requests
                        // count in the region capacity as a platform pod's (KnativeNodeService). It
                        // tolerates the dedicated-worker taint and prefers the other nodes, so it
                        // also runs on a cluster whose every node is a dedicated worker.
                        'spec' => DedicatedWorkers::systemPlacement() + [
                            'containers' => [[
                                'name' => 'publisher',
                                // the event gateway image (config knative.queue_consumer_image)
                                'image' => $region->registryImageRef((string) config('knative.queue_consumer_image')),
                                'env' => $env,
                                'ports' => [['containerPort' => self::PORT, 'name' => 'http']],
                                'resources' => ['requests' => $size, 'limits' => $size],
                                'readinessProbe' => [
                                    'httpGet' => ['path' => '/healthz', 'port' => 8081],
                                    'initialDelaySeconds' => 2,
                                    'periodSeconds' => 5,
                                ],
                                'livenessProbe' => [
                                    'httpGet' => ['path' => '/healthz', 'port' => 8081],
                                    'initialDelaySeconds' => 5,
                                    'periodSeconds' => 10,
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => ['name' => self::NAME, 'namespace' => $namespace, 'labels' => $labels],
                'spec' => [
                    'type' => 'ClusterIP',
                    'selector' => $labels,
                    'ports' => [['name' => 'http', 'port' => self::PORT, 'targetPort' => self::PORT, 'protocol' => 'TCP']],
                ],
            ],
        ];
    }
}
