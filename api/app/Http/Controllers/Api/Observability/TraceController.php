<?php

namespace App\Http\Controllers\Api\Observability;

use App\Http\Controllers\Api\Controller;
use App\Models\Project;
use App\Services\Observability\Tracing\TempoUnavailableException;
use App\Services\Observability\Tracing\TraceQueryService;
use App\Services\Observability\Tracing\TracingNotQueryableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Traces of a project, read server-side from the region's Tempo (TraceQL scoped to the project).
 *
 * @tags Observability
 */
class TraceController extends Controller
{
    public function __construct(private TraceQueryService $traces) {}

    /**
     * GET /api/projects/{project}/traces
     *
     * @queryParam status string all|error|ok
     * @queryParam min_duration_ms number minimum duration of the server span
     * @queryParam path string substring of the request path
     * @queryParam from int unix seconds (default: an hour ago)
     * @queryParam to int unix seconds (default: now)
     * @queryParam limit int 1–200
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(TraceQueryService::STATUSES)],
            'min_duration_ms' => ['nullable', 'numeric', 'min:0', 'max:3600000'],
            'path' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'integer', 'min:0'],
            'to' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        if (isset($filters['from'], $filters['to']) && $filters['from'] >= $filters['to']) {
            return response()->json(['message' => __('observability.tracing.invalid_range'), 'errors' => ['from' => [__('observability.tracing.invalid_range')]]], 422);
        }

        return $this->guarded(fn () => response()->json(['data' => $this->traces->search($project, $filters)]));
    }

    /** GET /api/projects/{project}/traces/{traceId} — the waterfall of one trace. */
    public function show(Project $project, string $traceId): JsonResponse
    {
        if (! preg_match('/^[0-9a-fA-F]{32}$/', $traceId)) {
            abort(404);
        }

        return $this->guarded(function () use ($project, $traceId) {
            $trace = $this->traces->show($project, $traceId);

            return $trace ? response()->json(['data' => $trace]) : response()->json(['message' => __('observability.tracing.trace_not_found')], 404);
        });
    }

    /** GET /api/projects/{project}/traces/{traceId}/logs — the project's log lines with this trace id. */
    public function logs(Project $project, string $traceId): JsonResponse
    {
        if (! preg_match('/^[0-9a-fA-F]{32}$/', $traceId)) {
            abort(404);
        }

        return $this->guarded(function () use ($project, $traceId) {
            $lines = $this->traces->logs($project, $traceId);

            return $lines === null
                ? response()->json(['message' => __('observability.tracing.trace_not_found')], 404)
                : response()->json(['data' => $lines]);
        });
    }

    private function guarded(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (TracingNotQueryableException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'not_queryable'], 409);
        } catch (TempoUnavailableException $e) {
            return response()->json(['message' => __('observability.tracing.tempo_unavailable'), 'detail' => $e->getMessage()], 503);
        }
    }
}
