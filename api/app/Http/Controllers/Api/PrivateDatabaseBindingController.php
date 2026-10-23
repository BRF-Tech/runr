<?php

namespace App\Http\Controllers\Api;

use App\Models\KnativeRegion;
use App\Models\PrivateDatabaseBinding;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\Environments\DeployTarget;
use App\Services\Environments\EnvironmentResolver;
use App\Services\Residency\RegionPolicy;
use App\Services\Skupper\SkupperLinkParser;
use App\Services\Skupper\SkupperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Private connections of a project to Managed PostgreSQL through Skupper v2 — of its own workload
 * or of one of its environments (docs/environments.md) — plus the region-level Skupper capability
 * (detection, site).
 */
class PrivateDatabaseBindingController extends Controller
{
    public const SSLMODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    public function __construct(
        private readonly SkupperService $skupper,
        private readonly SkupperLinkParser $parser,
    ) {}

    /** The connections of the project's own workload, or of one environment with ?environment= (docs/environments.md). */
    public function index(Request $request, Project $project): JsonResponse
    {
        $target = DeployTarget::environment($project, EnvironmentResolver::resolve($project, $request->query('environment')));

        $connections = [];
        foreach ($target->privateDatabaseBindings()->get() as $binding) {
            /** @var PrivateDatabaseBinding $binding */
            $connections[] = $this->present($binding, $project);
        }

        return response()->json(['data' => $connections]);
    }

    /**
     * A connection of the project's own workload, or with `environment` (a name or id) of one of
     * its environments: it lives in the namespace of the region the environment runs in, and only
     * the environment's workloads get its env vars. Names and env var prefixes are unique per
     * project environment.
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'environment' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:30', 'regex:/^[a-z]([-a-z0-9]*[a-z0-9])?$/'],
            'env_prefix' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z][A-Z0-9_]*_$/'],
            'routing_key' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'sslmode' => ['nullable', Rule::in(self::SSLMODES)],
            'link_token' => ['required', 'string', 'max:65535'],
            'database' => ['required', 'string', 'max:63'],
            'username' => ['required', 'string', 'max:63'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $environment = EnvironmentResolver::resolve($project, $data['environment'] ?? null);
        $target = DeployTarget::environment($project, $environment);

        if ($target->privateDatabaseBindings()->where('name', $data['name'])->exists()) {
            return $this->invalid('name', __('validation.unique', ['attribute' => 'name']));
        }
        $prefix = $data['env_prefix'] ?? '';
        if ($target->privateDatabaseBindings()->where('env_prefix', $prefix)->exists()) {
            return $this->invalid('env_prefix', __('api.private_connection.env_prefix_taken'));
        }

        // The region the workload runs in: the project's, or the environment's own
        $region = $target->knativeRegion;
        if (! $region || ! $region->kubeconfig || $region->is_demo) {
            return $this->invalid($environment ? 'environment' : 'project', __('api.private_connection.no_region'));
        }

        // The Skupper link reaches the database from the primary region only (docs/global-projects.md)
        if ($project->isGlobal()) {
            return $this->invalid('project', __('api.global.private_connection_refused'));
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
            if ($this->skupper->routingKeyInUse($region, $target, $data['routing_key'])) {
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
        if ($environment !== null) {
            $binding->setAttribute('environment_id', $environment->getKey());
        }
        $binding->id = $id;
        $binding->save();

        try {
            $this->skupper->apply($binding, $target, $link, [
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

            Log::warning('Private connection apply failed', ['project' => $project->slug, 'workload' => $target->slug, 'error' => $e->getMessage()]);

            return response()->json(['message' => __('api.private_connection.apply_failed', ['error' => $e->getMessage()])], 502);
        }

        return response()->json(['data' => $this->present($binding->fresh(), $project), 'env_synced' => $this->syncEnv($target)], 201);
    }

    public function status(Project $project, string $privateConnection): JsonResponse
    {
        $binding = $this->bindingOf($project, $privateConnection);
        $region = $binding->target($project)?->knativeRegion;

        try {
            $status = $region ? $this->skupper->status($binding, $region) : ['state' => 'error'];
        } catch (\Throwable $e) {
            return response()->json(['message' => __('api.private_connection.region_unreachable', ['error' => $e->getMessage()])], 502);
        }

        return response()->json(['data' => $this->present($binding, $project), 'status' => $status]);
    }

    public function destroy(Project $project, string $privateConnection): JsonResponse
    {
        $binding = $this->bindingOf($project, $privateConnection);
        $target = $binding->target($project);
        $region = $target?->knativeRegion;

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

        return response()->json(['message' => __('api.private_connection.deleted'), 'env_synced' => $target !== null && $this->syncEnv($target)]);
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

    /** The env of the workload the connection belongs to (the project's own, or an environment's). */
    private function syncEnv(Project $target): bool
    {
        try {
            $this->skupper->syncProjectEnv($target->fresh() ?? $target);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Private connection env sync failed', ['project' => $target->slug, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** A connection of the project — of its own workload or of any of its environments. */
    private function bindingOf(Project $project, string $id): PrivateDatabaseBinding
    {
        /** @var PrivateDatabaseBinding */
        return PrivateDatabaseBinding::query()->where('project_id', $project->getKey())->findOrFail($id);
    }

    /**
     * A connection as the API shows it, with the environment it belongs to (`environment`: a
     * name, null for the project's own workload).
     *
     * @return array<string, mixed>
     */
    private function present(PrivateDatabaseBinding $binding, Project $project): array
    {
        $environmentId = $binding->getAttribute('environment_id');
        $name = $environmentId ? ProjectEnvironment::query()->where('project_id', $project->getKey())->whereKey($environmentId)->value('name') : null;

        return $binding->toArray() + ['environment_id' => $environmentId ?: null, 'environment' => $name];
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
