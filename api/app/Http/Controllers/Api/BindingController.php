<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\BindingResource;
use App\Models\Binding;
use App\Models\BindingUsageRecord;
use App\Services\Bindings\BindingEnv;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\BindingManager;
use App\Services\Bindings\BindingQuotas;
use App\Services\Bindings\Postgres\PostgresSql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bindings of the organization (docs/bindings.md): managed KV, object storage, Postgres and
 * external credentials. Credentials leave only through credentials() (bindings.view_credentials).
 *
 * @tags Bindings
 */
class BindingController extends Controller
{
    public function __construct(
        private BindingManager $manager,
        private BindingQuotas $quotas,
    ) {}

    /** Bindings with their project attachments and measured usage. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('bindings.view');

        $bindings = Binding::query()
            ->with(['region', 'attachments.project'])
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('knative_region_id'), fn ($q, $region) => $q->where(fn ($w) => $w->where('knative_region_id', $region)->orWhere('type', 'external')))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => BindingResource::collection($bindings)->resolve(),
            'abilities' => $this->abilities(),
        ]);
    }

    /** Binding types: env keys, plans, defaults and maxima. */
    public function types(): JsonResponse
    {
        Gate::authorize('bindings.view');

        return response()->json([
            'data' => [
                'kv' => [
                    'env_keys' => BindingEnv::KEYS['kv'],
                    'plans' => ['shared'],
                    'defaults' => ['memory_mb' => (int) config('bindings.kv.default_memory_mb')],
                    'max' => ['memory_mb' => (int) config('bindings.kv.max_memory_mb')],
                ],
                'object' => [
                    'env_keys' => BindingEnv::KEYS['object'],
                    'plans' => ['shared'],
                    'defaults' => ['quota_gb' => (int) config('bindings.object.default_quota_gb')],
                    'max' => ['quota_gb' => (int) config('bindings.object.max_quota_gb')],
                ],
                'postgres' => [
                    'env_keys' => BindingEnv::KEYS['postgres'],
                    'plans' => ['shared', 'dedicated'],
                    'defaults' => [
                        'storage_gb' => (int) config('bindings.postgres.default_storage_gb'),
                        'connection_limit' => (int) config('bindings.postgres.default_connection_limit'),
                        'statement_timeout' => (string) config('bindings.postgres.default_statement_timeout'),
                        'instances' => (int) config('bindings.postgres.dedicated.default_instances'),
                        'dedicated_storage_gb' => (int) config('bindings.postgres.dedicated.default_storage_gb'),
                    ],
                    'max' => [
                        'storage_gb' => (int) config('bindings.postgres.max_storage_gb'),
                        'connection_limit' => (int) config('bindings.postgres.max_connection_limit'),
                        'instances' => (int) config('bindings.postgres.dedicated.max_instances'),
                    ],
                ],
                'external' => [
                    'env_keys' => [],
                    'plans' => ['external'],
                    'defaults' => [],
                    'max' => ['values' => (int) config('bindings.external.max_values')],
                ],
            ],
            'retention_days' => (int) config('bindings.retention_days'),
            'abilities' => $this->abilities(),
        ]);
    }

    /** Limits of the organization, claimed sizes and measured bytes. */
    public function quota(): JsonResponse
    {
        Gate::authorize('bindings.view');

        return response()->json(['data' => $this->quotas->summary()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('bindings.manage');

        $data = $this->validateBinding($request);

        try {
            $binding = $this->manager->create($data, $request->user()?->getKey() !== null ? (string) $request->user()->getKey() : null);
        } catch (BindingException $e) {
            return $this->invalid('binding', $e->getMessage());
        }

        return response()->json(['data' => (new BindingResource($binding->fresh(['region', 'attachments.project'])))->resolve()], 201);
    }

    public function show(Binding $binding): JsonResponse
    {
        Gate::authorize('bindings.view');

        return response()->json([
            'data' => (new BindingResource($binding->load(['region', 'attachments.project'])))->resolve(),
            'abilities' => $this->abilities(),
        ]);
    }

    /** Change limits (or the values of an external binding). */
    public function update(Request $request, Binding $binding): JsonResponse
    {
        Gate::authorize('bindings.manage');

        $data = $request->validate(array_merge($this->settingsRules(), [
            'settings' => ['nullable', 'array'],
            'values' => ['nullable', 'array', 'max:'.(int) config('bindings.external.max_values')],
            'values.*' => ['nullable', 'string', 'max:4096'],
        ]));

        if (isset($data['values'])) {
            if ($binding->type !== 'external') {
                return $this->invalid('values', __('api.bindings.values_external_only'));
            }
            $this->assertEnvKeys($data['values']);
        }

        try {
            $binding = $this->manager->update($binding, (array) ($data['settings'] ?? []), $data['values'] ?? null);
        } catch (BindingException $e) {
            return $this->invalid('settings', $e->getMessage());
        }

        return response()->json(['data' => (new BindingResource($binding->load(['region', 'attachments.project'])))->resolve()]);
    }

    /** Delete with retention: body {confirm: <binding name>}. */
    public function destroy(Request $request, Binding $binding): JsonResponse
    {
        Gate::authorize('bindings.manage');

        if ($request->input('confirm') !== $binding->name) {
            return $this->invalid('confirm', __('api.bindings.confirm_name'));
        }

        try {
            $binding = $this->manager->scheduleDeletion($binding);
        } catch (BindingException $e) {
            return $this->invalid('binding', $e->getMessage());
        }

        return response()->json([
            'message' => __('api.bindings.deletion_scheduled', ['date' => $binding->purge_at->toDateString()]),
            'data' => (new BindingResource($binding->load(['region', 'attachments'])))->resolve(),
        ], 202);
    }

    public function restore(Binding $binding): JsonResponse
    {
        Gate::authorize('bindings.manage');

        try {
            $binding = $this->manager->restore($binding);
        } catch (BindingException $e) {
            return $this->invalid('binding', $e->getMessage());
        }

        return response()->json(['message' => __('api.bindings.restored'), 'data' => (new BindingResource($binding->load(['region', 'attachments'])))->resolve()]);
    }

    /** New credentials + rolling restart of the attached workloads (queued). */
    public function rotate(Binding $binding): JsonResponse
    {
        Gate::authorize('bindings.manage');

        try {
            $this->manager->requestRotation($binding);
        } catch (BindingException $e) {
            return $this->invalid('binding', $e->getMessage());
        }

        return response()->json(['message' => __('api.bindings.rotation_queued'), 'data' => (new BindingResource($binding->fresh(['region', 'attachments.project'])))->resolve()], 202);
    }

    /** Live health of the binding on its data service. */
    public function status(Binding $binding): JsonResponse
    {
        Gate::authorize('bindings.view');

        return response()->json(['data' => (new BindingResource($binding->load('region')))->resolve(), 'health' => $this->manager->health($binding)]);
    }

    /** The binding's env vars with their values (audited: binding.credentials.reveal; never cached). */
    public function credentials(Binding $binding): JsonResponse
    {
        Gate::authorize('bindings.view_credentials');

        return response()
            ->json(['data' => $this->manager->reveal($binding->load('region'))])
            ->header('Cache-Control', 'no-store, private');
    }

    /** Daily usage records (storage GB-hours, instance hours, cost) of the last ?days= days. */
    public function usage(Request $request, Binding $binding): JsonResponse
    {
        Gate::authorize('bindings.view');

        $days = max(1, min(366, (int) $request->query('days', 30)));
        $records = BindingUsageRecord::query()
            ->where('binding_id', $binding->id)
            ->whereDate('record_date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('record_date')
            ->get();

        return response()->json(['data' => $records->map(fn (BindingUsageRecord $r) => [
            'record_date' => $r->record_date?->toDateString(),
            'storage_gb_hours' => (float) $r->storage_gb_hours,
            'instance_hours' => (float) $r->instance_hours,
            'peak_bytes' => (int) $r->peak_bytes,
            'final_cost' => (float) $r->final_cost,
            'currency' => $r->currency,
        ])->values()->all()]);
    }

    // ── validation ──────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function validateBinding(Request $request, ?string $forcedRegion = null, string $prefix = ''): array
    {
        $p = $prefix === '' ? '' : $prefix.'.';
        $data = $request->validate(array_merge([
            $p.'name' => ['required', 'string', 'max:30', 'regex:'.Binding::NAME_PATTERN, Rule::unique('bindings', 'name')],
            $p.'type' => ['required', Rule::in(Binding::TYPES)],
            $p.'plan' => ['nullable', Rule::in([Binding::PLAN_SHARED, Binding::PLAN_DEDICATED])],
            $p.'knative_region_id' => $forcedRegion ? ['nullable', 'string'] : ['nullable', 'required_unless:'.$p.'type,external', 'string'],
            $p.'values' => ['nullable', 'required_if:'.$p.'type,external', 'array', 'max:'.(int) config('bindings.external.max_values')],
            $p.'values.*' => ['nullable', 'string', 'max:4096'],
            $p.'settings' => ['nullable', 'array'],
        ], $this->settingsRules($p)));

        $data = $prefix === '' ? $data : (array) data_get($data, $prefix, []);

        if ($forcedRegion !== null && ($data['type'] ?? null) !== 'external') {
            $data['knative_region_id'] = $forcedRegion;
        }
        if (($data['type'] ?? null) === 'external') {
            $this->assertEnvKeys((array) ($data['values'] ?? []), $p.'values');
            $data['knative_region_id'] = null;
        }
        if (($data['plan'] ?? null) === Binding::PLAN_DEDICATED && ($data['type'] ?? null) !== 'postgres') {
            throw ValidationException::withMessages([$p.'plan' => __('api.bindings.dedicated_postgres_only')]);
        }

        return $data;
    }

    private function settingsRules(string $p = ''): array
    {
        return [
            $p.'settings.memory_mb' => ['nullable', 'integer', 'min:8', 'max:'.(int) config('bindings.kv.max_memory_mb')],
            $p.'settings.quota_gb' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('bindings.object.max_quota_gb')],
            $p.'settings.storage_gb' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('bindings.postgres.max_storage_gb')],
            $p.'settings.connection_limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('bindings.postgres.max_connection_limit')],
            $p.'settings.statement_timeout' => ['nullable', 'string', 'regex:'.PostgresSql::TIMEOUT_PATTERN],
            $p.'settings.instances' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('bindings.postgres.dedicated.max_instances')],
            $p.'settings.backups' => ['nullable', 'boolean'],
        ];
    }

    private function assertEnvKeys(array $values, string $field = 'values'): void
    {
        foreach (array_keys($values) as $key) {
            if (! preg_match(BindingEnv::ENV_NAME_PATTERN, (string) $key) || strlen((string) $key) > 64) {
                throw ValidationException::withMessages([$field => __('api.bindings.invalid_env_key', ['key' => $key])]);
            }
        }
    }

    private function abilities(): array
    {
        return [
            'manage' => Gate::allows('bindings.manage'),
            'view_credentials' => Gate::allows('bindings.view_credentials'),
        ];
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
