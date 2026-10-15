<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Http\Controllers\Concerns\TenantRegionTrait;
use Modules\Tenancy\Jobs\DeleteTenantJob;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

class TenantController extends Controller
{
    use TenantRegionTrait;

    public function index(Request $request): JsonResponse
    {
        $tenants = Tenant::withCount(['users', 'regions'])->get()
            ->map(fn ($t) => array_merge($t->toArray(), [
                'user_count' => $t->users_count,
                'region_count' => $t->regions_count,
            ]));

        return response()->json($tenants);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:100|unique:tenants,slug|alpha_dash',
        ]);

        $tenant = Tenant::create($data);

        // Create tenant schema first (TenantUser hooks sync users to schema)
        $tenant->database()->manager()->createDatabase($tenant);
        $this->runTenantMigrations($tenant);

        // Assign creator as owner (triggers user sync to tenant schema)
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $request->user()->id,
            'role' => 'owner',
        ]);

        return response()->json($tenant->load('users'), 201);
    }

    public function show(string $id): JsonResponse
    {
        $tenant = Tenant::with('users:id,name,email', 'regions')->findOrFail($id);

        return response()->json($tenant);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
        ]);

        $tenant->update($data);

        return response()->json($tenant);
    }

    /**
     * Start (or retry) deleting a tenant: status → deleting, DeleteTenantJob does the teardown
     * (every project's workloads on every region, domain entries, labelled leftovers, the agent of
     * tenant-owned regions) and drops the database only when nothing was left behind — otherwise
     * the tenant stays as delete_failed. `force=true` drops it regardless.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $force = $request->boolean('force');

        // A running deletion is not started twice; one whose worker died (no failed() callback
        // reached) may be retried once the job's timeout has passed
        $running = $tenant->status === Tenant::STATUS_DELETING
            && $tenant->updated_at?->gt(now()->subSeconds(DeleteTenantJob::TIMEOUT + 60));
        if ($running) {
            return response()->json(['message' => __('api.tenant.deletion_running'), 'tenant' => $tenant], 409);
        }

        $tenant->forceFill(['status' => Tenant::STATUS_DELETING, 'status_message' => null])->save();

        DeleteTenantJob::dispatch($tenant->id, $force, $request->user()?->id);

        return response()->json([
            'message' => __('api.tenant.deletion_started'),
            'tenant' => $tenant->fresh(),
        ], 202);
    }

    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $request->attributes->get('tenant');

        $role = $tenant
            ? ($user->is_admin ? 'admin' : TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('role'))
            : ($user->is_admin ? 'admin' : null);

        return response()->json([
            'tenant' => $tenant,
            'role' => $role,
            'mode' => $tenant ? 'tenant' : 'central',
            'tenants' => $this->userTenants($user),
        ]);
    }

    protected function userTenants($user): array
    {
        $roles = TenantUser::where('user_id', $user->id)
            ->pluck('role', 'tenant_id')
            ->all();

        // Admin sees all tenants, regular users only see their own
        $query = Tenant::orderBy('name');
        if (! $user->is_admin) {
            $query->whereIn('id', array_keys($roles));
        }

        return $query->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
            'role' => $user->is_admin ? 'admin' : ($roles[$t->id] ?? null),
        ])->all();
    }

    public function switchTenant(Request $request): JsonResponse
    {
        $request->validate(['tenant_id' => 'required|string']);
        $user = $request->user();

        // Admin can switch to any tenant
        if (! $user->is_admin) {
            $hasAccess = TenantUser::where('user_id', $user->id)
                ->where('tenant_id', $request->tenant_id)
                ->exists();

            if (! $hasAccess) {
                return response()->json(['message' => __('api.tenant.access_denied')], 403);
            }
        }

        // Touch updated_at to mark as last active (if pivot exists)
        TenantUser::where('user_id', $user->id)
            ->where('tenant_id', $request->tenant_id)
            ->update(['updated_at' => now()]);

        $tenant = Tenant::findOrFail($request->tenant_id);

        return response()->json(['tenant' => $tenant]);
    }

    protected function runTenantMigrations(Tenant $tenant): void
    {
        $tenant->run(function () {
            \Artisan::call('migrate', config('tenancy.migration_parameters'));
        });
    }
}
