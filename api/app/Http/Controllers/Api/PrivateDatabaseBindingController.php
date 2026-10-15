<?php

namespace App\Http\Controllers\Api;

use App\Models\KnativeRegion;
use App\Models\PrivateDatabaseBinding;
use App\Models\Project;
use App\Services\Residency\RegionPolicy;
use App\Services\Skupper\SkupperLinkParser;
use App\Services\Skupper\SkupperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Private connections of a project to Managed PostgreSQL through Skupper v2,
 * plus the region-level Skupper capability (detection, site).
 */
class PrivateDatabaseBindingController extends Controller
{
    public const SSLMODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    public function __construct(
        private readonly SkupperService $skupper,
        private readonly SkupperLinkParser $parser,
    ) {}

    public function index(Project $project): JsonResponse
    {
        return response()->json(['data' => $project->privateDatabaseBindings()->get()]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:30', 'regex:/^[a-z]([-a-z0-9]*[a-z0-9])?$/',
                Rule::unique('private_db_bindings', 'name')->where('project_id', $project->id)],
            'env_prefix' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z][A-Z0-9_]*_$/'],
            'routing_key' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'sslmode' => ['nullable', Rule::in(self::SSLMODES)],
            'link_token' => ['required', 'string', 'max:65535'],
            'database' => ['required', 'string', 'max:63'],
            'username' => ['required', 'string', 'max:63'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $prefix = $data['env_prefix'] ?? '';
        if ($project->privateDatabaseBindings()->where('env_prefix', $prefix)->exists()) {
            return $this->invalid('env_prefix', __('api.private_connection.env_prefix_taken'));
        }

        $region = $project->knativeRegion;
        if (! $region || ! $region->kubeconfig || $region->is_demo) {
            return $this->invalid('project', __('api.private_connection.no_region'));
        }

        // Data residency (docs/supply-chain.md)
        app(RegionPolicy::class)->authorize($region, RegionPolicy::BINDING_CREATE, field: 'project');

        try {
            $link = $this->parser->parse($data['link_token']);
        } catch (\InvalidArgumentException $e) {
            return $this->invalid('link_token', $e->getMessage());
        }

        try {
            if (! $this->skupper->available($region)) {
                return $this->invalid('project', __('api.private_connection.skupper_unavailable', ['region' => $region->name]));
            }
            if ($this->skupper->routingKeyInUse($region, $project, $data['routing_key'])) {
                return $this->invalid('routing_key', __('api.private_connection.routing_key_in_use'));
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => __('api.private_connection.region_unreachable', ['error' => $e->getMessage()])], 502);
        }

        $id = strtolower((string) Str::ulid());
        $binding = new PrivateDatabaseBinding([
            'project_id' => $project->id,
            'name' => $data['name'],
            'resource_name' => PrivateDatabaseBinding::resourceNameFor($data['name'], $id),
            'env_prefix' => $prefix,
            'routing_key' => $data['routing_key'],
            'port' => $data['port'] ?? 5432,
            'sslmode' => $data['sslmode'] ?? 'require',
            'link_kind' => $link['kind'] === 'AccessToken' ? 'access_token' : 'link',
        ]);
        $binding->id = $id;
        $binding->save();

        try {
            $this->skupper->apply($binding, $project, $link, [
                'database' => $data['database'],
                'username' => $data['username'],
                'password' => $data['password'],
            ]);
        } catch (\Throwable $e) {
            // leave nothing half-applied behind
            try {
                $this->skupper->remove($binding, $region);
            } catch (\Throwable) {
                // region unreachable: the error below is reported
            }
            $binding->delete();

            Log::warning('Private connection apply failed', ['project' => $project->slug, 'error' => $e->getMessage()]);

            return response()->json(['message' => __('api.private_connection.apply_failed', ['error' => $e->getMessage()])], 502);
        }

        return response()->json(['data' => $binding->fresh(), 'env_synced' => $this->syncEnv($project)], 201);
    }

    public function status(Project $project, string $privateConnection): JsonResponse
    {
        $binding = $project->privateDatabaseBindings()->findOrFail($privateConnection);
        $region = $project->knativeRegion;

        try {
            $status = $region ? $this->skupper->status($binding, $region) : ['state' => 'error'];
        } catch (\Throwable $e) {
            return response()->json(['message' => __('api.private_connection.region_unreachable', ['error' => $e->getMessage()])], 502);
        }

        return response()->json(['data' => $binding, 'status' => $status]);
    }

    public function destroy(Project $project, string $privateConnection): JsonResponse
    {
        $binding = $project->privateDatabaseBindings()->findOrFail($privateConnection);
        $region = $project->knativeRegion;

        if ($region?->kubeconfig) {
            try {
                $removed = $this->skupper->remove($binding, $region);
            } catch (\Throwable $e) {
                $removed = false;
                Log::warning('Private connection removal failed', ['binding' => $binding->resource_name, 'error' => $e->getMessage()]);
            }
            if (! $removed) {
                return response()->json(['message' => __('api.private_connection.remove_failed')], 502);
            }
        }

        $binding->delete();

        return response()->json(['message' => __('api.private_connection.deleted'), 'env_synced' => $this->syncEnv($project)]);
    }

    // ── Region capability ────────────────────────────────────────────────────

    public function regionStatus(KnativeRegion $knativeRegion): JsonResponse
    {
        if (! $knativeRegion->kubeconfig) {
            return response()->json(['data' => ['available' => false, 'site' => null]]);
        }

        try {
            return response()->json(['data' => $this->skupper->regionStatus($knativeRegion)]);
        } catch (\Throwable $e) {
            return response()->json(['message' => __('api.private_connection.region_unreachable', ['error' => $e->getMessage()])], 502);
        }
    }

    public function ensureSite(KnativeRegion $knativeRegion): JsonResponse
    {
        if (! $knativeRegion->kubeconfig) {
            return $this->invalid('region', __('api.private_connection.no_region'));
        }

        try {
            if (! $this->skupper->available($knativeRegion)) {
                return $this->invalid('region', __('api.private_connection.skupper_unavailable', ['region' => $knativeRegion->name]));
            }

            return response()->json(['data' => $this->skupper->ensureSite($knativeRegion)]);
        } catch (\Throwable $e) {
            return response()->json(['message' => __('api.private_connection.apply_failed', ['error' => $e->getMessage()])], 502);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function syncEnv(Project $project): bool
    {
        try {
            $this->skupper->syncProjectEnv($project->fresh());

            return true;
        } catch (\Throwable $e) {
            Log::warning('Private connection env sync failed', ['project' => $project->slug, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
