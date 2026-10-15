<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\BindingResource;
use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\Project;
use App\Services\Bindings\BindingEnv;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\BindingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Bindings attached to a project: attach an existing one or create + attach a new one (with an
 * env prefix, e.g. ORDERS_ → ORDERS_DATABASE_URL), change the prefix, detach. Changes need
 * env.edit on the project (route) and bindings.manage organization-wide — attaching hands the
 * binding's credentials to the project's code.
 *
 * @tags Bindings
 */
class ProjectBindingController extends Controller
{
    public function __construct(private BindingManager $manager) {}

    /** Attachments of the project + bindings that can still be attached. */
    public function index(Project $project): JsonResponse
    {
        // Route: projects.view on the project; the organization's other bindings need bindings.view
        $attachments = BindingAttachment::query()
            ->where('project_id', $project->id)
            ->with('binding.region')
            ->orderBy('created_at')
            ->get();

        $attached = $attachments->pluck('binding_id')->all();
        $available = ! Gate::allows('bindings.view') ? collect() : Binding::query()
            ->with('region')
            ->whereNotIn('id', $attached)
            ->whereNotIn('status', [Binding::STATUS_DELETING, Binding::STATUS_PURGING])
            ->where(fn ($q) => $q->where('knative_region_id', $project->knative_region_id)->orWhere('type', 'external'))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $attachments->map(fn (BindingAttachment $a) => BindingResource::attachment($a))->values()->all(),
            'available' => BindingResource::collection($available)->resolve(),
            'secret' => BindingEnv::secretName($project),
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
        ]);
        $prefix = (string) ($request->input('env_prefix') ?? '');

        try {
            if ($request->filled('binding_id')) {
                $binding = Binding::query()->with('region')->findOrFail($request->input('binding_id'));
            } else {
                if (! $project->knative_region_id) {
                    return $this->invalid('binding', __('api.bindings.project_without_region'));
                }
                $data = $bindings->validateBinding($request, (string) $project->knative_region_id, 'binding');
                $binding = $this->manager->create($data, $request->user()?->getKey() !== null ? (string) $request->user()->getKey() : null);
            }

            $attachment = $this->manager->attach($binding, $project, $prefix);
        } catch (BindingException $e) {
            return $this->invalid('binding', $e->getMessage());
        }

        $attachment->load('binding.region');

        return response()->json(['data' => BindingResource::attachment($attachment)], 201);
    }

    /** Change the env prefix of an attachment. */
    public function update(Request $request, Project $project, string $attachment): JsonResponse
    {
        Gate::authorize('bindings.manage');

        $data = $request->validate(['env_prefix' => ['nullable', 'string', 'max:32', 'regex:'.BindingAttachment::PREFIX_PATTERN]]);
        $model = $this->find($project, $attachment);

        try {
            $model = $this->manager->updateAttachment($model, (string) ($data['env_prefix'] ?? ''));
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
