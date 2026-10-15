<?php

namespace App\Http\Controllers\Api\Sandboxes;

use App\Http\Resources\SandboxResource;
use App\Models\Sandbox;
use App\Models\SandboxEvent;
use App\Services\Sandboxes\SandboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * @tags Sandboxes
 *
 * Sandbox lifecycle: create, list, get, delete, TTL, idle keepalive, pause / resume, network,
 * activity log (docs/sandbox-api.md).
 */
class SandboxesController extends SandboxApiController
{
    public function __construct(private SandboxService $sandboxes) {}

    /**
     * List sandboxes of the organization.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('sandboxes.view');

        $data = $request->validate([
            'status' => 'nullable|in:active,running,paused,pending,ended,all',
            'region' => 'nullable|string|max:64',
            'runtime' => 'nullable|string|max:32',
            'metadata' => 'nullable|array',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
            'all_tenants' => 'nullable|boolean',
        ]);

        $query = Sandbox::query()->whereNotIn('status', Sandbox::POOL_STATUSES);
        if (! ($request->boolean('all_tenants') && $this->platformView($request))) {
            $tenantId = $this->tenantId();
            $query->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId));
        }

        match ($data['status'] ?? 'active') {
            'active' => $query->whereIn('status', Sandbox::ACTIVE_STATUSES),
            'running' => $query->where('status', Sandbox::STATUS_RUNNING),
            'paused' => $query->where('status', Sandbox::STATUS_PAUSED),
            'pending' => $query->whereIn('status', [Sandbox::STATUS_PENDING, Sandbox::STATUS_CLAIMING]),
            'ended' => $query->whereIn('status', [...Sandbox::ENDED_STATUSES, Sandbox::STATUS_TERMINATING]),
            'all' => $query,
        };

        if (! empty($data['region'])) {
            $query->where('knative_region_id', $data['region']);
        }
        if (! empty($data['runtime'])) {
            $query->where('runtime', $data['runtime']);
        }

        $total = (clone $query)->count();
        $perPage = (int) ($data['per_page'] ?? 50);
        $page = (int) ($data['page'] ?? 1);
        $rows = $query->orderByDesc('created_at')->get();

        // metadata[key]=value filters (JSON columns differ per database: filtered here)
        $filters = (array) ($data['metadata'] ?? []);
        if ($filters !== []) {
            $rows = $rows->filter(function (Sandbox $s) use ($filters) {
                foreach ($filters as $key => $value) {
                    if ((string) ($s->metadata[$key] ?? '') !== (string) $value) {
                        return false;
                    }
                }

                return true;
            })->values();
            $total = $rows->count();
        }

        $rows = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => SandboxResource::collection($rows)->resolve($request),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    /**
     * Create a sandbox.
     *
     * 201 when it runs, 202 while it is still starting (poll GET), 502 when it could not start.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('sandboxes.create');

        $data = $request->validate([
            'runtime' => 'nullable|string|max:32',
            'version' => 'nullable|string|max:32',
            'image' => ['nullable', 'string', 'max:512', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/:@+-]*$/'],
            'cpu' => 'nullable',
            'memory' => 'nullable',
            'disk' => 'nullable',
            'ttl_seconds' => 'nullable|integer|min:10',
            'idle_timeout' => 'nullable|integer|min:0',
            'network' => 'nullable',
            'env' => 'nullable|array|max:'.(int) config('sandbox.limits.max_env', 100),
            'files' => 'nullable|array|max:'.(int) config('sandbox.limits.max_files', 200),
            'files.*.path' => 'required|string|max:4096',
            'files.*.content_base64' => 'present|nullable|string',
            'files.*.mode' => 'nullable',
            'region' => 'nullable|string|max:64',
            'sandbox_class' => 'nullable|string|max:64',
            'read_only_root' => 'nullable|boolean',
            'pids_limit' => 'nullable|integer|min:16',
            'metadata' => 'nullable|array|max:'.(int) config('sandbox.limits.max_metadata', 50),
            'metadata.*' => 'nullable|max:1024',
            'wait' => 'nullable|boolean',
            'start_timeout' => 'nullable|integer|min:5|max:600',
        ]);
        $data['wait'] = $request->has('wait') ? $request->boolean('wait') : true;

        $sandbox = $this->sandboxes->create(
            $data,
            $this->tenantId(),
            $this->userId($request),
            (bool) ($request->user()?->is_admin ?? false),
        );

        $status = match ($sandbox->status) {
            Sandbox::STATUS_RUNNING => 201,
            Sandbox::STATUS_FAILED => 502,
            default => 202,
        };

        $body = ['data' => (new SandboxResource($sandbox))->resolve($request)];
        if ($status === 502) {
            $body = ['message' => __('sandboxes.start_failed', ['error' => (string) $sandbox->error]), 'reason' => 'start_failed'] + $body;
        }

        return response()->json($body, $status);
    }

    public function show(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox);

        return response()->json(['data' => (new SandboxResource($row))->resolve($request)]);
    }

    public function destroy(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.create');
        $row = $this->sandboxes->terminate($row, Sandbox::END_DELETED);

        return response()->json(['data' => (new SandboxResource($row))->resolve($request)]);
    }

    /** New expiry: now + ttl_seconds (capped by the region's max TTL from the start). */
    public function timeout(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.create');
        $data = $request->validate(['ttl_seconds' => 'required|integer|min:10']);

        return response()->json(['data' => (new SandboxResource($this->sandboxes->extend($row, (int) $data['ttl_seconds'])))->resolve($request)]);
    }

    /** Reset the idle timer. */
    public function keepalive(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');

        return response()->json(['data' => (new SandboxResource($this->sandboxes->keepalive($row)))->resolve($request)]);
    }

    public function pause(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');

        return response()->json(['data' => (new SandboxResource($this->sandboxes->pause($row)))->resolve($request)]);
    }

    public function resume(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.exec');

        return response()->json(['data' => (new SandboxResource($this->sandboxes->resume($row)))->resolve($request)]);
    }

    public function network(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox, 'sandboxes.create');
        $request->validate(['network' => 'present']);

        return response()->json(['data' => (new SandboxResource($this->sandboxes->updateNetwork($row, $request->input('network'))))->resolve($request)]);
    }

    /** Activity log; pod=1 adds the pod's phase, conditions, events and log. */
    public function logs(Request $request, string $sandbox): JsonResponse
    {
        $row = $this->findSandbox($request, $sandbox);

        $events = SandboxEvent::where('sandbox_id', $row->id)->orderByDesc('created_at')->limit(500)->get()
            ->map(fn (SandboxEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'message' => $e->message,
                'data' => $e->data,
                'user_id' => $e->user_id,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->values();

        $data = ['events' => $events];
        if ($request->boolean('pod') && ! $row->isEnded()) {
            $data['pod'] = $this->podInfo($row);
        }

        return response()->json(['data' => $data]);
    }

    private function podInfo(Sandbox $row): ?array
    {
        try {
            $region = $this->sandboxes->regionOf($row);
            $k8s = $this->sandboxes->k8s($region);
            $pod = $k8s->getResourceStatus('v1', 'Pod', $row->pod_name, $row->namespace);
            if (! $pod) {
                return null;
            }
            $events = $k8s->listResources('v1', 'Event', $row->namespace) ?? [];
            $mine = array_values(array_filter($events['items'] ?? [], fn ($e) => ($e['involvedObject']['name'] ?? '') === $row->pod_name));

            return [
                'name' => $row->pod_name,
                'namespace' => $row->namespace,
                'node' => $pod['spec']['nodeName'] ?? null,
                'phase' => $pod['status']['phase'] ?? null,
                'conditions' => array_map(fn ($c) => ['type' => $c['type'] ?? null, 'status' => $c['status'] ?? null, 'reason' => $c['reason'] ?? null, 'message' => $c['message'] ?? null], $pod['status']['conditions'] ?? []),
                'events' => array_map(fn ($e) => ['type' => $e['type'] ?? null, 'reason' => $e['reason'] ?? null, 'message' => $e['message'] ?? null, 'at' => $e['lastTimestamp'] ?? $e['eventTime'] ?? null], array_slice($mine, -50)),
                'log' => $k8s->getPodLogs($row->pod_name, $row->namespace, 'sandbox', 200),
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
