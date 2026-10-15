<?php

namespace App\Services;

use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Ai\Models\ModelServiceReservations;
use App\Services\Concerns\RegionDemoCodeTrait;
use App\Services\Helpers\ResourceReservation;
use App\Services\Manifests\QueueConsumerManifests;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class RegionDemoService
{
    use RegionDemoCodeTrait;

    /** Queue stream of the demo: the backend adds to it, the email sender consumes it. */
    public const QUEUE_STREAM = 'contact-forms';

    public function __construct(private ProjectService $projectService) {}

    public function validatePrerequisites(KnativeRegion $region): void
    {
        if (! $region->has_serving) {
            throw new InvalidArgumentException('Serving is not ready on this region.');
        }
        if (! $region->has_redis) {
            throw new InvalidArgumentException('Redis is not ready on this region.');
        }
        if (! $region->has_registry) {
            throw new InvalidArgumentException('Registry is not ready on this region.');
        }
        foreach (['nodejs', 'php', 'go'] as $rt) {
            if (! ExecutorImage::defaultVersion($rt)) {
                throw new InvalidArgumentException("No default version for runtime: {$rt}");
            }
        }

        $this->validateCapacity($region);
    }

    /**
     * The demo projects are admitted like any other project (region capacity with their
     * effective size — the service type's default, ResourceReservation): all four must fit next
     * to the region's projects, otherwise none is created.
     */
    public function validateCapacity(KnativeRegion $region): void
    {
        $needed = ResourceReservation::zero();
        foreach ($this->demoProjects() as $project) {
            $reservation = ResourceReservation::forConfig($project['config'], $project['service_type'], $region);
            $needed['cpu_m'] += $reservation['cpu_m'];
            $needed['memory_mb'] += $reservation['memory_mb'];
        }

        $used = ResourceReservation::sum($region->projects()->with('xaasConfig', 'faasConfig', 'containerConfig')->get());
        // running model services reserve capacity too
        $models = ModelServiceReservations::sum($region);
        $used = ['cpu_m' => $used['cpu_m'] + $models['cpu_m'], 'memory_mb' => $used['memory_mb'] + $models['memory_mb']];
        $limit = ['cpu_m' => (int) $region->total_cpu_m, 'memory_mb' => (int) $region->total_memory_mb];

        foreach (['cpu_m', 'memory_mb'] as $key) {
            if ($limit[$key] > 0 && $limit[$key] < $used[$key] + $needed[$key]) {
                throw new InvalidArgumentException(sprintf(
                    'Not enough free capacity on this region for the demo projects: they reserve %dm CPU / %d MB, available %dm CPU / %d MB (total %dm / %d MB).',
                    $needed['cpu_m'], $needed['memory_mb'],
                    max(0, $limit['cpu_m'] - $used['cpu_m']), max(0, $limit['memory_mb'] - $used['memory_mb']),
                    $limit['cpu_m'], $limit['memory_mb'],
                ));
            }
        }
    }

    /**
     * The four demo projects (ProjectService::create() data without the region), keyed like the
     * result of createDemoProjects(). No size: they get their service type's default.
     *
     * @return array<string, array>
     */
    public function demoProjects(): array
    {
        return [
            'frontend' => [
                'name' => 'contact-frontend',
                'service_type' => 'xaas',
                'runtime' => 'nodejs',
                'runtime_version' => ExecutorImage::defaultVersion('nodejs'),
                'config' => ['handler' => 'handler', 'trigger_type' => 'http'],
            ],
            'backend' => [
                'name' => 'contact-backend',
                'service_type' => 'faas',
                'runtime' => 'php',
                'runtime_version' => ExecutorImage::defaultVersion('php'),
                'config' => ['handler' => 'handler.handler', 'trigger_type' => 'http'],
            ],
            'emailSender' => [
                'name' => 'contact-email-sender',
                'service_type' => 'xaas',
                'runtime' => 'go',
                'runtime_version' => ExecutorImage::defaultVersion('go'),
                'config' => ['handler' => 'Handler', 'trigger_type' => 'queue', 'queue_stream' => self::QUEUE_STREAM],
            ],
            'stats' => [
                'name' => 'contact-stats',
                'service_type' => 'caas',
                'config' => [
                    'image' => $this->statsDockerImage(),
                    'command' => $this->statsCommand(),
                    'trigger_type' => 'schedule',
                    'schedule' => '*/5 * * * *',
                ],
            ],
        ];
    }

    public function createDemoProjects(KnativeRegion $region, string $userId): array
    {
        $base = ['knative_region_id' => $region->id, 'is_region_demo' => true];

        return array_map(
            fn (array $project) => $this->projectService->create(array_merge($base, $project)),
            $this->demoProjects(),
        );
    }

    public function setEnvVars(array $projects, string $backendUrl, string $redisHost, string $redisPort): void
    {
        $redis = ['REDIS_HOST' => $redisHost, 'REDIS_PORT' => $redisPort, 'QUEUE_STREAM' => $this->queueStream()];

        $projects['frontend']->xaasConfig->update([
            'env_vars' => ['BACKEND_URL' => $backendUrl],
        ]);
        $projects['backend']->faasConfig->update(['env_vars' => $redis]);
        $projects['stats']->containerConfig->update(['env_vars' => $redis]);
    }

    /**
     * The stream the email sender's queue consumer reads: the same resolution as the consumer
     * Deployment (KnativeQueueConsumerTrait), so in a tenant it carries the tenant suffix
     * (contact-forms-<tenant>).
     */
    public function queueStream(): string
    {
        return QueueConsumerManifests::resolveStreamName(self::QUEUE_STREAM);
    }

    public function commitDemoCode(array $projects, string $backendUrl, string $userId): void
    {
        $this->projectService->saveXaaSCode(
            $projects['frontend'], $this->frontendCode($backendUrl), $userId, 'init: contact form frontend'
        );

        [$redisHost, $redisPort] = $this->parseRedisAddress($projects['backend']->knativeRegion);
        $this->projectService->saveFaaSFiles(
            $projects['backend'], $this->backendFiles($redisHost, $redisPort), $userId, 'init: contact form backend'
        );

        $this->projectService->saveXaaSCode(
            $projects['emailSender'], $this->emailSenderCode(), $userId, 'init: email sender'
        );
    }

    /**
     * The frontend calls the backend from its own pod, so it uses the in-cluster address of the
     * backend's Knative Service — no public DNS record or publicly trusted certificate needed.
     */
    public function resolveBackendUrl(Project $backend, KnativeRegion $region): string
    {
        return "http://{$backend->slug}.{$region->project_namespace}.svc.cluster.local";
    }

    public function parseRedisAddress(KnativeRegion $region): array
    {
        $raw = $region->redis_address ?? '127.0.0.1:6379';

        // Handle redis:// scheme
        if (str_starts_with($raw, 'redis://')) {
            $parsed = parse_url($raw);

            return [$parsed['host'] ?? '127.0.0.1', (string) ($parsed['port'] ?? 6379)];
        }

        // host:port format
        $parts = explode(':', $raw);

        return [$parts[0], $parts[1] ?? '6379'];
    }

    public function cleanupDemoProjects(KnativeRegion $region): void
    {
        $projects = $region->projects()->where('is_region_demo', true)->get();

        // K8s teardown BEFORE DB cleanup (observer relies on active deployment)
        $deploySvc = app(KnativeDeployService::class);
        foreach ($projects as $project) {
            Log::info('Removing region demo project', ['project' => $project->slug]);
            $deployment = $project->deployments()->latest()->first();
            if ($deployment) {
                $deploySvc->teardown($project, $deployment);
            }
            $project->deployments()->delete();
            $project->versions()->delete();
            $project->xaasConfig?->delete();
            $project->faasConfig?->delete();
            $project->containerConfig?->delete();
            $project->delete();
        }
    }
}
