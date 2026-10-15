<?php

namespace App\Http\Controllers\Api\Sandboxes;

use App\Http\Controllers\Api\Controller;
use App\Models\Sandbox;
use App\Services\Sandboxes\SandboxContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Shared lookups of the Sandbox API controllers: a sandbox is visible to its organization (the
 * request's tenant context; null on a single-tenant install) — and to platform admins outside a
 * tenant context, who see every organization's sandboxes.
 */
abstract class SandboxApiController extends Controller
{
    protected function tenantId(): ?string
    {
        return SandboxContext::tenantId();
    }

    /** Platform admin in the central context of a multi-tenant install. */
    protected function platformView(Request $request): bool
    {
        return SandboxContext::tenancyEnabled()
            && (bool) ($request->user()?->is_admin ?? false)
            && SandboxContext::tenantId() === null;
    }

    protected function findSandbox(Request $request, string $id, string $ability = 'sandboxes.view'): Sandbox
    {
        Gate::authorize($ability);

        $query = Sandbox::query()->whereKey($id)->whereNotIn('status', Sandbox::POOL_STATUSES);
        if (! $this->platformView($request)) {
            $tenantId = $this->tenantId();
            $query->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId));
        }

        $sandbox = $query->first();
        if (! $sandbox) {
            abort(404, __('sandboxes.not_found'));
        }

        return $sandbox;
    }

    protected function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }

    /** env object of a request (string values). */
    protected function env(Request $request): array
    {
        $env = [];
        foreach ((array) $request->input('env', []) as $key => $value) {
            $env[(string) $key] = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
        }

        return $env;
    }

    /** stdin of a request: stdin (text) or stdin_base64. */
    protected function stdin(Request $request): ?string
    {
        if ($request->filled('stdin_base64')) {
            $bytes = base64_decode((string) $request->input('stdin_base64'), true);
            if ($bytes === false) {
                abort(422, __('sandboxes.base64_invalid'));
            }

            return $bytes;
        }

        return $request->has('stdin') ? (string) $request->input('stdin') : null;
    }
}
