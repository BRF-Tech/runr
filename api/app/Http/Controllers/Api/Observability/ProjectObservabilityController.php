<?php

namespace App\Http\Controllers\Api\Observability;

use App\Http\Controllers\Api\Controller;
use App\Models\Observability\ProjectObservabilitySetting;
use App\Models\Observability\RegionTracingConfig;
use App\Models\Project;
use App\Services\Observability\IngestUrl;
use App\Services\Observability\ObservabilityPodSpec;
use App\Services\Observability\Replay\ReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A project's tracing and request replay switches. Both apply to the function container at
 * deploy time: a change takes effect with the next deployment (requires_redeploy).
 *
 * @tags Observability
 */
class ProjectObservabilityController extends Controller
{
    /** GET /api/projects/{project}/observability */
    public function show(Project $project, ReplayService $replay, IngestUrl $ingest): JsonResponse
    {
        return response()->json(['data' => $this->payload($project, $replay, $ingest)]);
    }

    /** PUT /api/projects/{project}/observability/tracing — {enabled, sample_ratio} */
    public function updateTracing(Request $request, Project $project, ReplayService $replay, IngestUrl $ingest): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'sample_ratio' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        $region = $project->knativeRegion;
        if ($data['enabled'] && (! $region || ! RegionTracingConfig::forRegion($region)->acceptsSpans())) {
            return response()->json(['message' => __('observability.tracing.region_not_ready')], 409);
        }

        $settings = ProjectObservabilitySetting::forProject($project);
        $settings->tracing_enabled = (bool) $data['enabled'];
        if (isset($data['sample_ratio'])) {
            $settings->tracing_sample_ratio = (float) $data['sample_ratio'];
        }
        $settings->save();

        return response()->json(['data' => $this->payload($project, $replay, $ingest), 'requires_redeploy' => (bool) $project->activeDeployment]);
    }

    /**
     * PUT /api/projects/{project}/observability/replay —
     * {enabled, max_body_bytes, retention_days, redact_headers[], redact_query[]}
     */
    public function updateReplay(Request $request, Project $project, ReplayService $replay, IngestUrl $ingest): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'max_body_bytes' => ['nullable', 'integer', 'min:0', 'max:'.(int) config('observability.replay.max_body_bytes_ceiling', 1048576)],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('observability.replay.retention_days_max', 30)],
            'redact_headers' => ['nullable', 'array', 'max:50'],
            'redact_headers.*' => ['string', 'max:100', 'regex:/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/'],
            'redact_query' => ['nullable', 'array', 'max:50'],
            'redact_query.*' => ['string', 'max:100'],
        ]);

        if ($data['enabled'] && ! app(ObservabilityPodSpec::class)->capturable($project)) {
            return response()->json(['message' => __('observability.replay.code_runtimes_only')], 422);
        }

        $settings = ProjectObservabilitySetting::forProject($project);
        $wasOn = $settings->replay_enabled;

        $settings->replay_enabled = (bool) $data['enabled'];
        foreach (['max_body_bytes' => 'replay_max_body_bytes', 'retention_days' => 'replay_retention_days'] as $in => $column) {
            if (isset($data[$in])) {
                $settings->{$column} = (int) $data[$in];
            }
        }
        if (array_key_exists('redact_headers', $data)) {
            $settings->replay_redact_headers = ProjectObservabilitySetting::normalizeNames((array) $data['redact_headers']);
        }
        if (array_key_exists('redact_query', $data)) {
            $settings->replay_redact_query = ProjectObservabilitySetting::normalizeNames((array) $data['redact_query']);
        }
        // switched on (again): a new ingest token; tokens of earlier deployments stop working
        if ($settings->replay_enabled && (! $wasOn || ! $settings->replay_salt)) {
            $settings->rotateReplaySalt();
        }
        $settings->save();

        return response()->json(['data' => $this->payload($project, $replay, $ingest), 'requires_redeploy' => (bool) $project->activeDeployment]);
    }

    private function payload(Project $project, ReplayService $replay, IngestUrl $ingest): array
    {
        $region = $project->knativeRegion;
        $config = $region ? RegionTracingConfig::forRegion($region) : null;

        return ProjectObservabilitySetting::forProject($project)->toApi() + [
            'region_tracing' => $config ? [
                'mode' => $config->mode,
                'status' => $config->status,
                'queryable' => $config->isQueryable(),
                'accepts_spans' => $config->acceptsSpans(),
            ] : null,
            'replay_available' => app(ObservabilityPodSpec::class)->capturable($project) && $ingest->forRegion($region) !== null,
            'replay_target' => $replay->targetUrl($project),
        ];
    }
}
