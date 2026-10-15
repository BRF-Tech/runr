<?php

namespace App\Http\Controllers\Api\Ai;

use App\Http\Controllers\Api\Controller;
use App\Models\AiHubCredential;
use App\Models\KnativeRegion;
use App\Services\Ai\Engines\EngineCatalog;
use App\Services\Ai\Gpu\GpuInventory;
use App\Services\Ai\Gpu\GpuQuota;
use App\Services\Ai\Hub\HubClient;
use App\Services\Ai\Hub\HubException;
use App\Services\Ai\Hub\HubToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Hugging Face Hub browser (server-side proxy), serving engines, the organization's Hub token
 * and its GPU quota (docs/huggingface.md).
 *
 * @tags AI · Hub
 */
class HubController extends Controller
{
    public function __construct(private HubClient $hub) {}

    public function engines(): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        return response()->json(EngineCatalog::catalog());
    }

    public function search(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        $data = $request->validate([
            'search' => 'nullable|string|max:200',
            'task' => 'nullable|string|max:64|regex:/^[a-z0-9-]+$/',
            'library' => 'nullable|string|max:64|regex:/^[a-z0-9_.-]+$/',
            'sort' => ['nullable', Rule::in(array_keys(HubClient::SORTS))],
            'limit' => 'nullable|integer|min:1|max:50',
            'cursor' => 'nullable|string|max:500',
        ]);

        try {
            return response()->json($this->hub->search(
                $data['search'] ?? null,
                $data['task'] ?? null,
                $data['library'] ?? null,
                $data['sort'] ?? 'downloads',
                (int) ($data['limit'] ?? 20),
                $data['cursor'] ?? null,
            ));
        } catch (HubException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }
    }

    /** Model info with engine suggestions for the deploy dialog (?knative_region_id= sizes for that region's GPUs). */
    public function info(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        $data = $request->validate([
            'id' => 'required|string|max:200',
            'revision' => 'nullable|string|max:100|regex:/^[A-Za-z0-9._\/-]+$/',
            'knative_region_id' => 'nullable|string',
        ]);

        try {
            $info = $this->hub->info($data['id'], $data['revision'] ?? 'main');
        } catch (HubException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        $region = ! empty($data['knative_region_id']) ? KnativeRegion::find($data['knative_region_id']) : null;
        $hasGpu = $region ? app(GpuInventory::class)->stored($region)['summary']['allocatable'] > 0 : true;
        $suggest = EngineCatalog::suggest($info, $hasGpu);

        if ($info['gated']) {
            $suggest['warnings'][] = app(HubToken::class)->get() ? 'gated' : 'gated_needs_token';
        }

        $info['suggested'] = $suggest;
        $info['warnings'] = $suggest['warnings'];
        unset($info['suggested']['warnings']);

        return response()->json($info);
    }

    public function token(): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        $credential = AiHubCredential::huggingFace();

        return response()->json([
            'configured' => $credential !== null,
            'hint' => $credential?->hint,
            'updated_at' => $credential?->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Store the token (encrypted) and update the token Secret in the organization's regions.
     * ?validate=1 checks it with the Hub first (not on consoles without Hub access).
     */
    public function setToken(Request $request, HubToken $token): JsonResponse
    {
        abort_unless(Gate::allows('models.manage'), 403);

        $data = $request->validate([
            'token' => 'required|string|min:8|max:512|regex:/^\S+$/',
            'validate' => 'nullable|boolean',
        ]);

        if (($data['validate'] ?? false) && $this->hub->enabled()) {
            try {
                if ($this->hub->whoami($data['token']) === null) {
                    return response()->json(['message' => __('ai.hub.token_rejected'), 'errors' => ['token' => [__('ai.hub.token_rejected')]]], 422);
                }
            } catch (HubException $e) {
                return response()->json(['message' => $e->getMessage()], $e->status());
            }
        }

        $credential = $token->set($data['token'], (string) $request->user()?->getKey());
        dispatch(fn () => app(HubToken::class)->syncAllRegions())->afterResponse();

        return response()->json([
            'configured' => true,
            'hint' => $credential->hint,
            'updated_at' => $credential->updated_at?->toIso8601String(),
        ]);
    }

    public function deleteToken(HubToken $token): JsonResponse
    {
        abort_unless(Gate::allows('models.manage'), 403);

        $token->forget();
        dispatch(fn () => app(HubToken::class)->syncAllRegions())->afterResponse();

        return response()->json(['configured' => false, 'hint' => null, 'updated_at' => null]);
    }

    public function gpuQuota(Request $request, GpuQuota $quota): JsonResponse
    {
        abort_unless(Gate::allows('models.view'), 403);

        $data = $request->validate(['knative_region_id' => 'required|string']);
        $region = KnativeRegion::find($data['knative_region_id']);
        abort_unless($region !== null, 404);

        $limit = $quota->exempt() ? null : $quota->quota($region);
        $used = $quota->used($region);

        return response()->json([
            'quota' => $limit,
            'used' => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
        ]);
    }
}
