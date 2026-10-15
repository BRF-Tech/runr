<?php

namespace App\Services\Observability\Alerts;

use App\Models\Notification;
use App\Models\Observability\AlertRule;
use App\Models\Observability\ProjectObservabilitySetting;
use App\Models\Observability\RegionTracingConfig;
use App\Models\Project;
use App\Services\Observability\TenantScope;

/**
 * Rule evaluation:
 *  - error_rate / p95_latency / cold_start_rate: every minute (observability:run alerts),
 *    per traced project the rule covers, from the span metrics; fires above the threshold (with
 *    at least min_requests in the window), resolves below it
 *  - uptime_down: event-driven by the incident state machine (UptimeService)
 *  - resource_threshold: event-driven by the built-in region CPU / memory check
 *    (ResourceThresholdService creates a resource_threshold Notification → resourceThreshold())
 */
class AlertEvaluator
{
    public function __construct(
        private AlertManager $alerts,
        private SpanMetricsQuery $metrics,
    ) {}

    public function evaluateAll(): int
    {
        $evaluated = 0;
        TenantScope::each(function () use (&$evaluated) {
            $evaluated += $this->evaluateScope();
        });

        return $evaluated;
    }

    public function evaluateScope(): int
    {
        $rules = AlertRule::query()->whereIn('type', AlertRule::METRIC_TYPES)->where('is_enabled', true)->get();
        $count = 0;

        foreach ($rules as $rule) {
            foreach ($this->projectsOf($rule) as $project) {
                $this->evaluate($rule, $project);
                $count++;
            }
            $rule->forceFill(['last_evaluated_at' => now()])->save();
        }

        return $count;
    }

    /** One metric rule for one project. */
    public function evaluate(AlertRule $rule, Project $project): void
    {
        $region = $project->knativeRegion;
        if (! $region || ! RegionTracingConfig::forRegion($region)->acceptsSpans()) {
            return;
        }

        $window = max(1, (int) $rule->param('window_minutes', 5));
        $minVolume = max(0, (float) $rule->param('min_requests', 10));
        $threshold = (float) ($rule->type === 'p95_latency' ? $rule->param('threshold_ms', 1000) : $rule->param('threshold', 5));

        [$value, $volume] = $this->metrics->measure($region, $rule->type, $project->slug, $window);
        $rule->forceFill(['last_value' => $value])->save();

        if ($value === null || $volume < $minVolume) {
            return; // no data / not enough traffic: neither fires nor resolves
        }

        $unit = $rule->type === 'p95_latency' ? ' ms' : ' %';
        $data = [
            'project' => $project->name,
            'region' => $region->name,
            'value' => round($value, 2).$unit,
            'threshold' => $threshold.$unit,
            'window' => $window.' min',
            'url' => self::projectUrl($project, 'traces'),
        ];

        if ($value > $threshold) {
            $this->alerts->fire(
                $rule,
                $project->getKey(),
                __('observability.alerts.metric_title.'.$rule->type, ['project' => $project->name]),
                __('observability.alerts.metric_text', ['value' => $data['value'], 'threshold' => $data['threshold'], 'window' => $window]),
                $data,
                $project->getKey(),
            );
        } else {
            $this->alerts->resolve($rule, $project->getKey(), __('observability.alerts.metric_resolved', ['value' => $data['value']]));
        }
    }

    /**
     * resource_threshold rules for a region CPU / memory notification of ResourceThresholdService.
     */
    public function resourceThreshold(Notification $notification): void
    {
        if ($notification->type !== 'resource_threshold') {
            return;
        }

        $data = $notification->data ?? [];
        $regionId = (string) ($data['region_id'] ?? '');
        $resource = (string) ($data['resource_type'] ?? '');
        $level = $notification->level;

        foreach ($this->alerts->rulesFor('resource_threshold') as $rule) {
            if ($rule->region_id && $rule->region_id !== $regionId) {
                continue;
            }
            $want = (string) $rule->param('resource', 'any');
            if ($want !== 'any' && $want !== $resource) {
                continue;
            }
            if ($rule->param('min_level', 'warning') === 'critical' && $level !== 'critical') {
                continue;
            }

            $this->alerts->fire(
                $rule,
                "region:{$regionId}:{$resource}",
                $notification->title,
                __('observability.alerts.resource_text', [
                    'region' => $data['region_name'] ?? $regionId,
                    'resource' => strtoupper($resource) === 'CPU' ? 'CPU' : 'Memory',
                    'percentage' => $data['percentage'] ?? '?',
                    'threshold' => $data['threshold'] ?? '?',
                ]),
                [
                    'region' => (string) ($data['region_name'] ?? ''),
                    'value' => ($data['percentage'] ?? '?').' %',
                    'threshold' => ($data['threshold'] ?? '?').' %',
                    'url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/settings?tab=resource-usage',
                ],
            );
        }
    }

    /** The traced projects a metric rule covers. */
    private function projectsOf(AlertRule $rule)
    {
        $traced = ProjectObservabilitySetting::query()->where('tracing_enabled', true)->pluck('project_id');

        return Project::query()
            ->whereIn('id', $rule->project_id ? $traced->intersect([$rule->project_id]) : $traced)
            ->with('knativeRegion')
            ->get();
    }

    public static function projectUrl(Project $project, string $tab = ''): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/').'/projects/'.$project->getKey().($tab !== '' ? '#'.$tab : '');
    }
}
