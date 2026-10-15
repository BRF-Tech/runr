<?php

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Models\ProjectSecret;
use App\Support\Secrets\ProjectSecretWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Secret environment variables of a project (docs/security.md, "Secret environment variables").
 *
 * Values are write-only: they can be set and replaced, the list shows names only. Reading a value
 * back takes the `secrets.view_values` ability (GET …/secrets/{name}/value). A change reaches the
 * workload with the next deploy (Kubernetes Secret <slug>-secret-env + secretKeyRef env entries).
 */
class ProjectSecretController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $secrets = $project->secrets()->with('updater:id,name')->get();

        return response()->json([
            'data' => $secrets->map(fn (ProjectSecret $s) => $s->toPublicArray())->values(),
            'can_view_values' => Gate::allows('secrets.view_values', $project),
        ])->header('Cache-Control', 'no-store');
    }

    /** PUT /projects/{project}/secrets/{name} — create or replace (write-only). */
    public function upsert(Request $request, Project $project, string $name): JsonResponse
    {
        $this->ensureSupported($project);

        $existing = $project->secrets()->where('name', $name)->first();

        Validator::make(
            ['name' => $name, 'value' => $request->input('value')],
            [
                'name' => ['required', 'string', 'max:255', $this->nameRule($project)],
                'value' => ['required', 'string', function ($attribute, $value, $fail) {
                    if (is_string($value) && strlen($value) > ProjectSecret::MAX_VALUE_BYTES) {
                        $fail(__('api.secret.value_too_large', ['max' => ProjectSecret::MAX_VALUE_BYTES / 1024]));
                    }
                }],
            ],
        )->after(function ($validator) use ($project, $existing) {
            if (! $existing && $project->secrets()->count() >= ProjectSecret::MAX_PER_PROJECT) {
                $validator->errors()->add('name', __('api.secret.too_many', ['max' => ProjectSecret::MAX_PER_PROJECT]));
            }
        })->validate();

        $secret = ProjectSecret::updateOrCreate(
            ['project_id' => $project->id, 'name' => $name],
            ['value' => (string) $request->input('value'), 'updated_by' => $request->user()?->id],
        );

        return response()->json([
            'data' => $secret->load('updater:id,name')->toPublicArray(),
            'requires_deploy' => true,
        ], $existing ? 200 : 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(Project $project, string $name): JsonResponse
    {
        $secret = $project->secrets()->where('name', $name)->firstOrFail();
        $secret->delete();

        return response()->json(['requires_deploy' => true]);
    }

    /** GET /projects/{project}/secrets/{name}/value — gated by `secrets.view_values`. */
    public function reveal(Project $project, string $name): JsonResponse
    {
        Gate::authorize('secrets.view_values', $project);

        $secret = $project->secrets()->where('name', $name)->firstOrFail();

        return response()->json(['name' => $secret->name, 'value' => $secret->value])
            ->header('Cache-Control', 'no-store, private');
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
}
