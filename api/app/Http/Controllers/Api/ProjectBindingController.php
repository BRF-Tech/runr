<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\BindingResource;
use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\Project;
use App\Services\Bindings\BindingEnv;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\BindingManager;
use App\Services\Environments\DeployTarget;
use App\Services\Environments\EnvironmentResolver;
use App\Services\Environments\EnvironmentScope;
use App\Support\SchemaColumns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Bindings attached to a project: attach an existing one or create + attach a new one (with an
 * env prefix, e.g. ORDERS_ → ORDERS_DATABASE_URL), change the prefix, detach. Changes need
 * env.edit on the project (route) and bindings.manage organization-wide — attaching hands the
 * binding's credentials to the project's code.
 *
 * Environments (docs/environments.md#bindings): `environment` (name or id) attaches to one
 * environment of the project instead of its own workload; the list shows every attachment with
 * its environment, or one environment's with ?environment=.
 *
 * @tags Bindings
 */
class ProjectBindingController extends Controller
{
    public function __construct(private BindingManager $manager) {}

    /** Attachments of the project (of one environment with ?environment=) + bindings that can still be attached. */
    public function index(Request $request, Project $project): JsonResponse
    {
        // Route: projects.view on the project; the organization's other bindings need bindings.view
        $environment = EnvironmentResolver::resolve($project, $request->query('environment'));
        $target = DeployTarget::environment($project, $environment);
        $query = $request->filled('environment')
            ? EnvironmentScope::constrain(BindingAttachment::query(), $target)
            : BindingAttachment::query()->where('project_id', $project->id);
        $attachments = $query
            ->with(SchemaColumns::has(new BindingAttachment, 'environment_id') ? ['binding.region', 'environment'] : ['binding.region'])
            ->orderBy('created_at')
            ->get();

        // A binding is attached to at most one environment of a project
        $attached = BindingAttachment::query()->where('project_id', $project->id)->pluck('binding_id')->all();
        $available = ! Gate::allows('bindings.view') ? collect() : Binding::query()
            ->with('region')
            ->whereNotIn('id', $attached)
            ->whereNotIn('status', [Binding::STATUS_DELETING, Binding::STATUS_PURGING])
            ->where(fn ($q) => $q->where('knative_region_id', $target->knative_region_id)->orWhere('type', 'external'))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $attachments->map(fn (BindingAttachment $a) => BindingResource::attachment($a))->values()->all(),
            'available' => BindingResource::collection($available)->resolve(),
            'secret' => BindingEnv::secretName($target),
            'abilities' => [
                'manage' => Gate::allows('bindings.manage'),
                'view_credentials' => Gate::allows('bindings.view_credentials'),
            ],
        ]);
    }

    /**
     * Attach: {binding_id, env_prefix} — or create + attach: {binding: {name, type, plan?, settings?, values?}, env_prefix}
     * (a new binding lives in the project's region).
     */
    public function store(Request $request, Project $project, BindingController $bindings): JsonResponse
    {
        Gate::authorize('bindings.manage');

        $request->validate([
            'binding_id' => ['nullable', 'required_without:binding', 'string'],
            'binding' => ['nullable', 'required_without:binding_id', 'array'],
            'env_prefix' => ['nullable', 'string', 'max:32', 'regex:'.BindingAttachment::PREFIX_PATTERN],
            // dynamic: a Postgres user of its own from the region's OpenBao (docs/bindings.md)
            'credentials' => ['nullable', 'string', 'in:static,dynamic'],
            'environment' => ['nullable', 'string', 'max:64'],
        ]);
        $prefix = (string) ($request->input('env_prefix') ?? '');
        $mode = (string) ($request->input('credentials') ?? 'static');
        $environment = EnvironmentResolver::resolve($project, $request->input('environment'));
        $regionId = DeployTarget::environment($project, $environment)->knative_region_id;

        try {
            if ($request->filled('binding_id')) {
                $binding = Binding::query()->with('region')->findOrFail($request->input('binding_id'));
            } else {
                if (! $regionId) {
                    return $this->invalid('binding', __('api.bindings.project_without_region'));
                }
                // A new binding lives in the region of the workload it is attached to
                $data = $bindings->validateBinding($request, (string) $regionId, 'binding');
                $binding = $this->manager->create($data, $request->user()?->getKey() !== null ? (string) $request->user()->getKey() : null);
            }

            $attachment = $this->manager->attach($binding, $project, $prefix, $mode, $environment);
        } catch (BindingException $e) {
            return $this->invalid('binding', $e->getMessage());
        }

        $attachment->load('binding.region');

        return response()->json(['data' => BindingResource::attachment($attachment)], 201);
    }

    /** Change the env prefix of an attachment, or its credentials (static | dynamic). */
    public function update(Request $request, Project $project, string $attachment): JsonResponse
    {
        Gate::authorize('bindings.manage');

        $data = $request->validate([
            'env_prefix' => ['nullable', 'string', 'max:32', 'regex:'.BindingAttachment::PREFIX_PATTERN],
            'credentials' => ['nullable', 'string', 'in:static,dynamic'],
        ]);
        $model = $this->find($project, $attachment);

        try {
            $prefix = array_key_exists('env_prefix', $data) ? (string) ($data['env_prefix'] ?? '') : (string) $model->env_prefix;
            $model = $this->manager->updateAttachment($model, $prefix, $data['credentials'] ?? null);
        } catch (BindingException $e) {
            return $this->invalid('env_prefix', $e->getMessage());
        }

        return response()->json(['data' => BindingResource::attachment($model->load('binding.region'))]);
    }

    public function destroy(Project $project, string $attachment): JsonResponse
    {
        Gate::authorize('bindings.manage');

        $this->manager->detach($this->find($project, $attachment));

        return response()->json(['message' => __('api.bindings.detached')]);
    }

    private function find(Project $project, string $attachment): BindingAttachment
    {
        return BindingAttachment::query()
            ->where('project_id', $project->id)
            ->with(['binding.region', 'project'])
            ->findOrFail($attachment);
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
