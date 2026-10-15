<?php

namespace App\Http\Controllers\Api\Observability;

use App\Http\Controllers\Api\Controller;
use App\Models\KnativeRegion;
use App\Services\Observability\Replay\ReplayService;
use App\Services\Observability\Uptime\UptimeService;
use App\Services\Shell\AgentController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Callbacks from the regions — outside the user authentication, each with its own credential:
 *  - replay captures from function pods (per-project HMAC token, ReplayTokens)
 *  - uptime checks / results of the region agent (the agent's per-region token)
 */
class ObservabilityIngestController extends Controller
{
    /** POST /api/internal/observability/replay/{key} */
    public function replay(Request $request, string $key, ReplayService $replays): JsonResponse
    {
        if (strlen((string) $request->getContent()) > 4 * 1024 * 1024) {
            return response()->json(['message' => 'capture too large'], 413);
        }

        $payload = $request->json()->all();
        if (! is_array($payload) || $payload === []) {
            return response()->json(['message' => 'invalid capture'], 422);
        }

        $result = $replays->ingest($key, $request->bearerToken(), $payload);

        return match ($result) {
            ReplayService::INGEST_STORED => response()->json(['status' => 'stored'], 202),
            ReplayService::INGEST_INVALID => response()->json(['message' => 'invalid key'], 404),
            default => response()->json(['message' => 'Unauthorized'], 401),
        };
    }

    /** GET /api/internal/observability/agent/{region}/uptime-checks */
    public function agentChecks(Request $request, string $region, UptimeService $uptime): JsonResponse
    {
        $model = $this->agentRegion($request, $region);
        if (! $model) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json(['checks' => $uptime->agentChecks($model)]);
    }

    /** POST /api/internal/observability/agent/{region}/uptime-results — {results: [...]} */
    public function agentResults(Request $request, string $region, UptimeService $uptime): JsonResponse
    {
        $model = $this->agentRegion($request, $region);
        if (! $model) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $results = $request->json('results');
        if (! is_array($results) || count($results) > 1000) {
            return response()->json(['message' => 'invalid results'], 422);
        }

        return response()->json(['recorded' => $uptime->recordAgentResults($model, $results)], 202);
    }

    /** The region when the request carries its agent's token (same check as the agent WebSocket). */
    private function agentRegion(Request $request, string $regionId): ?KnativeRegion
    {
        if (! app(AgentController::class)->authorizeAgent((string) $request->header('Authorization'), $regionId)) {
            return null;
        }

        return KnativeRegion::find($regionId);
    }
}
