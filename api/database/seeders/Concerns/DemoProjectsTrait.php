<?php

namespace Database\Seeders\Concerns;

use App\Models\Deployment;
use App\Models\DeploymentRollout;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\Helpers\ResourceReservation;
use App\Services\ProjectService;
use App\Services\Rollouts\DeployStrategy;
use App\Services\Rollouts\RolloutTraffic;
use App\Support\SchemaColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The demo projects of the install (DemoSeeder) and of an organization (the Tenancy module's
 * SeedTenantDemoJob): eleven projects of every type and trigger, each with four versions and a
 * deployment history a visitor can walk through: earlier deployments that were replaced, one
 * that failed, a rollback and a canary rollout. The demo region has no cluster (is_demo): the
 * history is written as it would have happened, nothing runs.
 */
trait DemoProjectsTrait
{
    use DemoVersionsTrait;

    /**
     * ProjectService::create() data without the region and the creator. `story` is the deployment
     * history (seedDemoDeployments); it is not a project attribute.
     *
     * @return list<array<string, mixed>>
     */
    protected function demoProjectSamples(): array
    {
        return [
            [
                'name' => 'demo-xaas-http',
                'service_type' => 'xaas',
                'runtime' => 'python',
                'runtime_version' => '3.12',
                'description' => 'Python HTTP function that answers with a JSON greeting. Its last release was rolled back to the version before.',
                'config' => ['trigger_type' => 'http', 'memory_mb' => 256, 'timeout_seconds' => 30],
                'story' => 'rollback',
            ],
            [
                'name' => 'demo-xaas-schedule',
                'service_type' => 'xaas',
                'runtime' => 'python',
                'runtime_version' => '3.12',
                'description' => 'Python function that runs at the top of every hour, a starting point for reports and clean-up jobs.',
                'config' => ['trigger_type' => 'schedule', 'schedule' => '0 * * * *', 'memory_mb' => 256, 'timeout_seconds' => 30],
                'story' => 'steady',
            ],
            [
                'name' => 'demo-faas-http',
                'service_type' => 'faas',
                'runtime' => 'nodejs',
                'runtime_version' => '22',
                'description' => 'Node.js HTTP function with its dependencies in package.json. New versions roll out as a canary release.',
                'config' => ['handler' => 'main.handler', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30],
                'story' => 'canary',
            ],
            [
                'name' => 'demo-faas-schedule',
                'service_type' => 'faas',
                'runtime' => 'nodejs',
                'runtime_version' => '22',
                'description' => 'Node.js function that runs every 15 minutes on a cron schedule.',
                'config' => ['handler' => 'main.handler', 'trigger_type' => 'schedule', 'schedule' => '*/15 * * * *', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30],
                'story' => 'steady',
            ],
            [
                'name' => 'demo-caas-http',
                'service_type' => 'caas',
                'description' => 'nginx container image served over HTTPS that scales to zero when it gets no requests.',
                'config' => ['image' => 'ghcr.io/brf-tech/runr/reqs/nginx:1.27-alpine', 'trigger_type' => 'http', 'cpu' => '500m', 'memory_mb' => 256, 'timeout_seconds' => 30],
                'story' => 'failed',
            ],
            [
                'name' => 'demo-caas-schedule',
                'service_type' => 'caas',
                'description' => 'Container job that runs every night at midnight and writes a line to its log.',
                'config' => ['image' => 'ghcr.io/brf-tech/runr/reqs/alpine:3.21', 'command' => ['echo', 'hello'], 'trigger_type' => 'schedule', 'schedule' => '0 0 * * *', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 60],
                'story' => 'steady',
            ],
            [
                'name' => 'demo-xaas-queue',
                'service_type' => 'xaas',
                'runtime' => 'python',
                'runtime_version' => '3.12',
                'description' => 'Python consumer of the order-events queue: one invocation per message.',
                'config' => ['trigger_type' => 'queue', 'queue_stream' => 'order-events', 'memory_mb' => 256, 'timeout_seconds' => 30],
                'story' => 'steady',
            ],
            [
                'name' => 'demo-faas-queue',
                'service_type' => 'faas',
                'runtime' => 'nodejs',
                'runtime_version' => '20',
                'description' => 'Node.js consumer of the payment-events queue.',
                'config' => ['handler' => 'main.handler', 'trigger_type' => 'queue', 'queue_stream' => 'payment-events', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30],
                'story' => 'failed',
            ],
            [
                'name' => 'demo-caas-queue',
                'service_type' => 'caas',
                'description' => 'Container that consumes the notification-events queue.',
                'config' => ['image' => 'ghcr.io/brf-tech/runr/reqs/nginx:1.27-alpine', 'trigger_type' => 'queue', 'queue_stream' => 'notification-events', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30],
                'story' => 'steady',
            ],
            // Project past its EOL (red card)
            [
                'name' => 'demo-xaas-eol',
                'service_type' => 'xaas',
                'runtime' => 'python',
                'runtime_version' => '3.9',
                'description' => 'Python 3.9 function. The runtime is past its end of life, so the card is red: move it to a newer version.',
                'config' => ['trigger_type' => 'http', 'memory_mb' => 256, 'timeout_seconds' => 30],
                'story' => 'steady',
            ],
            // Project nearing its EOL (yellow card)
            [
                'name' => 'demo-faas-sunsetting',
                'service_type' => 'faas',
                'runtime' => 'nodejs',
                'runtime_version' => '18',
                'description' => 'Node.js 18 function. The runtime reaches its end of life in a few weeks, so the card is yellow.',
                'config' => ['handler' => 'main.handler', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 30],
                'story' => 'steady',
            ],
        ];
    }

    /**
     * Creates the demo projects in $region with their versions and deployment histories.
     *
     * @return list<Project>
     */
    protected function seedDemoProjectSet(KnativeRegion $region, string $userId, string $username): array
    {
        $svc = app(ProjectService::class);
        $created = [];

        $samples = $this->demoProjectSamples();
        foreach ($samples as $index => $sample) {
            $story = $sample['story'] ?? 'steady';
            unset($sample['story']);

            $project = $svc->create(array_merge($sample, [
                'created_by' => $userId,
                'knative_region_id' => $region->id,
            ]));

            match ($project->service_type) {
                'xaas' => $this->seedXaaSVersions($svc, $project, $userId),
                'faas' => $this->seedFaaSVersions($svc, $project, $userId),
                'caas' => $this->seedCaaSVersions($svc, $project, $userId),
            };

            // The first sample the newest: the project list (newest first) reads in their order, and
            // the first card the tours open is the HTTP function with the rollback
            $this->seedDemoDeployments($project->fresh(), $region, $username, $story, count($samples) - 1 - $index);
            $created[] = $project;
        }

        return $created;
    }

    /**
     * The project's deployment history, spread over the last twelve days (a little later for each
     * project, so the lists do not show the same minutes). Every version is deployed in turn and
     * replaces the one before; the newest one serves (status active). The story adds:
     *
     *   failed    the third version failed to start; the fourth one fixed it
     *   rollback  the fourth version was rolled back to the third, with the config it ran with
     *   canary    the fourth version rolled out as a canary (10 % → 50 % → 100 %), promoted
     */
    protected function seedDemoDeployments(Project $project, KnativeRegion $region, string $username, string $story, int $index = 0): void
    {
        $versions = Version::query()->where('project_id', $project->id)->orderBy('number')->get();
        if ($versions->isEmpty()) {
            return;
        }

        $start = now()->subDays(12)->addMinutes($index * 47)->startOfMinute();
        $this->backdate($project, $start->copy()->subHour());

        $deployments = [];
        $previous = null;
        foreach ($versions as $i => $version) {
            $versionAt = $start->copy()->addHours((int) round($i * 2.6 * 24));
            $this->backdate($version, $versionAt);

            $deployedAt = $versionAt->copy()->addMinutes(12);
            $failed = $story === 'failed' && $version->number === 3;
            $deployment = $this->demoDeployment($project, $version, $username, $deployedAt, $failed ? 'failed' : 'active', [
                'knative_message' => $failed ? 'The container exited with code 1 before it became ready (missing environment variable PORT).' : null,
            ]);

            // The deployment before it served until this one was ready (a failed one replaced none)
            if (! $failed) {
                $previous?->forceFill(['status' => 'shut_down', 'ended_at' => $deployedAt->copy()->addMinute(), 'updated_at' => $deployedAt->copy()->addMinute()])->saveQuietly();
                $previous = $deployment;
            }
            $deployments[$version->number] = $deployment;
        }

        if ($story === 'rollback' && isset($deployments[3], $deployments[4])) {
            $rolledBackAt = $deployments[4]->created_at->copy()->addHours(5);
            $previous?->forceFill(['status' => 'shut_down', 'ended_at' => $rolledBackAt->copy()->addMinute(), 'updated_at' => $rolledBackAt->copy()->addMinute()])->saveQuietly();
            $this->demoDeployment($project, $deployments[3]->version, $username, $rolledBackAt, 'active', [
                'config_source' => Deployment::CONFIG_RESTORED,
            ]);
        }

        if ($story === 'canary' && isset($deployments[3], $deployments[4])) {
            $this->demoCanary($project, $region, $deployments[4], $deployments[3], $username);
        }
    }

    /** A deployment as a deploy to the demo region leaves it (KnativeDeployService: ready at once). */
    private function demoDeployment(Project $project, Version $version, string $username, Carbon $at, string $status, array $extra = []): Deployment
    {
        $attributes = [
            'project_id' => $project->id,
            'version_id' => $version->id,
            'status' => $status,
            'k8s_job_name' => 'run-'.$project->slug.'-v'.$version->number,
            'deployed_by' => $username,
            'knative_ready' => $status === 'active',
            'knative_message' => $extra['knative_message'] ?? null,
            'ready_at' => $status === 'active' ? $at->copy()->addMinute() : null,
        ];
        if ($status === 'failed' && SchemaColumns::has(new Deployment, 'ended_at')) {
            $attributes['ended_at'] = $at->copy()->addMinutes(3);
        }
        if (isset($extra['config_source']) && SchemaColumns::has(new Deployment, 'config_source')) {
            $attributes['config_source'] = $extra['config_source'];
        }

        $deployment = new Deployment($attributes);
        $deployment->created_at = $at;
        $deployment->updated_at = $at->copy()->addMinute();
        $deployment->saveQuietly();

        return $deployment->setRelation('version', $version);
    }

    /**
     * The newest version's canary rollout: 10 %, 50 %, then all of the traffic, promoted and the
     * previous revision released — a rollout that is over, so the rollout jobs leave it alone.
     * The project keeps the canary strategy for its next deploys.
     */
    private function demoCanary(Project $project, KnativeRegion $region, Deployment $candidate, Deployment $stable, string $username): void
    {
        $config = ResourceReservation::configOf($project);
        if ($config instanceof Model && SchemaColumns::has($config, 'deploy_strategy')) {
            $config->forceFill(['deploy_strategy' => DeployStrategy::defaults()->merge(['strategy' => DeployStrategy::CANARY])->toArray()])->saveQuietly();
        }

        if (! DeploymentRollout::available()) {
            return;
        }

        $options = DeployStrategy::defaults()->options(DeployStrategy::CANARY);
        $steps = $options['steps'];
        $interval = (int) $options['interval_minutes'];
        $at = $candidate->created_at->copy();
        $version = $candidate->version;
        $stableVersion = $stable->version ?? $stable->version()->first();

        $events = [[
            'at' => $at->toIso8601String(),
            'type' => 'created',
            'message' => __('rollouts.event.created', ['strategy' => __('rollouts.strategy.canary', [], 'en'), 'version' => $version->label], 'en'),
            'traffic' => 0,
            'actor' => $username,
        ], [
            'at' => $at->copy()->addMinute()->toIso8601String(),
            'type' => 'ready',
            'message' => __('rollouts.event.ready', ['revision' => RolloutTraffic::revisionName($project, $version, $candidate)], 'en'),
            'traffic' => 0,
            'actor' => 'system',
        ]];
        foreach ($steps as $i => $percent) {
            $events[] = [
                'at' => $at->copy()->addMinutes(1 + $i * $interval)->toIso8601String(),
                'type' => 'step',
                'message' => __('rollouts.event.step', ['step' => $i + 1, 'count' => count($steps), 'percent' => $percent], 'en'),
                'traffic' => $percent,
                'actor' => 'system',
            ];
        }
        $promotedAt = $at->copy()->addMinutes(1 + count($steps) * $interval);
        $events[] = ['at' => $promotedAt->toIso8601String(), 'type' => 'promoted', 'message' => __('rollouts.event.promoted', [], 'en'), 'traffic' => 100, 'actor' => 'system'];
        $events[] = ['at' => $promotedAt->toIso8601String(), 'type' => 'released', 'message' => __('rollouts.event.released', [], 'en'), 'traffic' => 100, 'actor' => 'system'];

        $rollout = new DeploymentRollout([
            'project_id' => $project->id,
            'deployment_id' => $candidate->id,
            'stable_deployment_id' => $stable->id,
            'region_id' => $region->id,
            'strategy' => DeploymentRollout::CANARY,
            'status' => DeploymentRollout::PROMOTED,
            'options' => $options,
            'revision' => RolloutTraffic::revisionName($project, $version, $candidate),
            'stable_revision' => $stableVersion ? RolloutTraffic::revisionName($project, $stableVersion, $stable) : null,
            'tag' => RolloutTraffic::TAGS[DeploymentRollout::CANARY] ?? 'canary',
            'traffic_percent' => 100,
            'step_index' => count($steps) - 1,
            'step_started_at' => $promotedAt->copy()->subMinutes($interval),
            'metrics' => ['tracing' => false, 'error_rate' => 0.4, 'p95_ms' => 182, 'volume' => 1240, 'window_minutes' => $interval, 'measured_at' => $promotedAt->toIso8601String()],
            'promoted_at' => $promotedAt,
            'released_at' => $promotedAt,
            'events' => $events,
            'created_by' => $username,
        ]);
        $rollout->created_at = $at;
        $rollout->updated_at = $promotedAt;
        $rollout->saveQuietly();

        // The stable deployment served until the promotion released it
        $stable->forceFill(['status' => 'shut_down', 'ended_at' => $promotedAt, 'updated_at' => $promotedAt])->saveQuietly();
    }

    /** Sets created_at / updated_at without touching anything else (no model events). */
    private function backdate(Project|Version $model, Carbon $at): void
    {
        $model->newQuery()->whereKey($model->getKey())->update(['created_at' => $at, 'updated_at' => $at]);
    }
}
