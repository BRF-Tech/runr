<?php

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\SecretStore;
use App\Services\Secrets\External\ExternalSecretStatusSync;
use App\Services\Secrets\ProjectSecrets;
use App\Services\Secrets\SecretBackendException;
use App\Support\Secrets\ProjectSecretWriter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Secret environment variables of a project (docs/security.md, "Secret environment variables";
 * docs/secret-stores.md).
 *
 * A variable is a value or a reference. A value is write-only: it goes straight into the region's
 * Kubernetes Secret <slug>-secret-env (pass-through) and RunR keeps the name, version, who changed
 * it when and a keyed fingerprint. A reference names a secret store of the organization, a path
 * and a key; External Secrets Operator syncs it into <slug>-ext-env. Reading a value back takes
 * the `secrets.view_values` ability and reads it from the region (GET …/secrets/{name}/value).
 * The workload reads every variable through secretKeyRef entries; a changed value reaches new
 * containers at once, every container with the next deploy.
 */
class ProjectSecretController extends Controller
{
    /**
     * The API document's shape of the list (index()): ProjectSecret::toPublicArray() — a value
     * names where it is stored, a reference its store and sync status
     */
    private const LIST_TYPE = 'array{data: list<array{name: string, source: "region"|"external"|"openbao", version: int, updated_at: string|null, updated_by: array{id: int, name: string}|null, reference?: array{store_id: string, store_name: string|null, provider: string|null, path: string|null, key: string|null}, sync?: array{status: "pending"|"synced"|"failed", message: string|null, synced_at: string|null}, stored_in?: "region"|"openbao"|"database", fingerprint?: string|null, rollback_copy?: bool}>, can_view_values: bool, storage: "region"|"database"}';

    #[OpenApiResponse(200, 'The project\'s secret variables (never their values), whether the user may read values and where new values go', type: self::LIST_TYPE)]
    public function index(Project $project): JsonResponse
    {
        $secrets = $project->secrets()->with(['updater:id,name', 'store:id,name,provider'])->get();

        return response()->json([
            'data' => $secrets->map(fn (ProjectSecret $s) => $s->toPublicArray())->values(),
            'can_view_values' => Gate::allows('secrets.view_values', $project),
            // Where new values go: region (pass-through) or database (deprecated setting)
            'storage' => ProjectSecrets::storage(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * PUT /projects/{project}/secrets/{name} — create or replace: {"value": "…"} (write-only) or
     * {"reference": {"store_id": "…", "path": "…", "key": "…"}}.
     */
    public function upsert(Request $request, Project $project, string $name): JsonResponse
    {
        $this->ensureSupported($project);

        $existing = $project->secrets()->where('name', $name)->first();
        $isReference = $request->has('reference');

        Validator::make(
            ['name' => $name, 'value' => $request->input('value'), 'reference' => $request->input('reference')],
            [
                'name' => ['required', 'string', 'max:255', $this->nameRule($project)],
                'value' => $isReference ? ['prohibited'] : ['required', 'string', function ($attribute, $value, $fail) {
                    if (is_string($value) && strlen($value) > ProjectSecret::MAX_VALUE_BYTES) {
                        $fail(__('api.secret.value_too_large', ['max' => ProjectSecret::MAX_VALUE_BYTES / 1024]));
                    }
                }],
                'reference' => $isReference ? ['required', 'array'] : ['nullable'],
                'reference.store_id' => $isReference ? ['required', 'string', 'max:26'] : [],
                'reference.path' => $isReference ? ['required', 'string', 'max:512', 'regex:/^[^\s]+$/'] : [],
                'reference.key' => $isReference ? ['nullable', 'string', 'max:255', 'regex:/^[^\s]+$/'] : [],
            ],
        )->after(function ($validator) use ($project, $existing, $isReference, $request, $name) {
            if (! $existing && $project->secrets()->count() >= ProjectSecret::MAX_PER_PROJECT) {
                $validator->errors()->add('name', __('api.secret.too_many', ['max' => ProjectSecret::MAX_PER_PROJECT]));
            }
            if ($isReference && is_string($request->input('reference.store_id')) && ! SecretStore::query()->whereKey($request->input('reference.store_id'))->exists()) {
                $validator->errors()->add('reference.store_id', __('api.secret_store.not_found'));
            }
            // Pass-through: the value lives in the region's cluster
            if (! $isReference && ($error = ProjectSecretWriter::clusterError($project->knativeRegion, [$name => 'set']))) {
                $validator->errors()->add('value', $error);
            }
        })->validate();

        try {
            if ($isReference) {
                $store = SecretStore::query()->findOrFail($request->input('reference.store_id'));
                $secret = app(ProjectSecrets::class)->setReference(
                    $project, $name, $store,
                    (string) $request->input('reference.path'),
                    $request->filled('reference.key') ? (string) $request->input('reference.key') : null,
                    $request->user()?->id,
                );
            } else {
                app(ProjectSecrets::class)->setValues($project, [$name => (string) $request->input('value')], $request->user()?->id);
                $secret = $project->secrets()->where('name', $name)->firstOrFail();
            }
        } catch (SecretBackendException $e) {
            return $this->backendError($e);
        }

        return response()->json([
            'data' => $secret->load(['updater:id,name', 'store:id,name,provider'])->toPublicArray(),
            'requires_deploy' => true,
        ], $existing ? 200 : 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(Project $project, string $name): JsonResponse
    {
        $project->secrets()->where('name', $name)->firstOrFail();
        app(ProjectSecrets::class)->remove($project, [$name]);

        return response()->json(['requires_deploy' => true]);
    }

    /**
     * GET /projects/{project}/secrets/{name}/value — gated by `secrets.view_values`, read from the
     * region (503 when the region does not answer; a reference is read in its store, not here).
     */
    public function reveal(Project $project, string $name): JsonResponse
    {
        Gate::authorize('secrets.view_values', $project);

        $secret = $project->secrets()->with('store:id,name')->where('name', $name)->firstOrFail();

        try {
            $read = app(ProjectSecrets::class)->reveal($project, $secret);
        } catch (SecretBackendException $e) {
            return $this->backendError($e);
        }

        return response()->json([
            'name' => $secret->name,
            'value' => $read['value'],
            // false: the region's value is not the one RunR wrote (changed in the cluster)
            'matches_fingerprint' => $read['matches_fingerprint'],
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * POST /projects/{project}/secrets/refresh — sync the project's references now and read their
     * status (External Secrets Operator's force-sync).
     */
    #[OpenApiResponse(200, 'The project\'s secret variables after the sync (as GET …/secrets)', type: self::LIST_TYPE)]
    #[OpenApiResponse(503, 'The region did not answer', type: 'array{message: string, reason: string}')]
    public function refresh(Project $project): JsonResponse
    {
        if ($project->secrets()->where('source', ProjectSecret::SOURCE_EXTERNAL)->exists()) {
            try {
                app(ExternalSecretStatusSync::class)->refresh($project);
            } catch (\Throwable $e) {
                return response()->json(['message' => __('api.secret.region_unreachable', ['region' => $project->knativeRegion->name ?? '—']), 'reason' => SecretBackendException::UNREACHABLE], 503);
            }
        }

        return $this->index($project->fresh() ?? $project);
    }

    /** Same naming rules as plain env vars (EnvVars), and no plain env var of the same name. */
    private function nameRule(Project $project): \Closure
    {
        return function ($attribute, $value, $fail) use ($project) {
            if ($error = ProjectSecretWriter::nameError((string) $value, ProjectSecretWriter::plainEnv($project))) {
                $fail($error);
            }
        };
    }

    private function ensureSupported(Project $project): void
    {
        if (! $project->isSupportedType()) {
            abort(422, __('api.project.service_type_unsupported'));
        }
    }

    private function backendError(SecretBackendException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status())
            ->header('Cache-Control', 'no-store');
    }
}
