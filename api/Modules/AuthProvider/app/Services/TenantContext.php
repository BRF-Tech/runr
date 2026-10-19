<?php

namespace Modules\AuthProvider\Services;

/**
 * The organization an SSO flow runs in. In a multi-tenant install every tenant keeps its own
 * providers and linked accounts in its schema, so a flow enters the tenant before it reads them —
 * and every step of one flow (redirect, callback, link confirmation) enters the same tenant.
 * Without the Tenancy module there is only the installation itself.
 */
class TenantContext
{
    private const TENANT_MODEL = 'Modules\\Tenancy\\Models\\Tenant';

    /** Slug of the tenant the request runs in (null: central context or single-tenant install). */
    public function currentSlug(): ?string
    {
        if (! $this->available() || ! tenancy()->initialized) {
            return null;
        }

        $slug = data_get(tenancy()->tenant, 'slug');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /** ID of the tenant the request runs in (null: central context or single-tenant install). */
    public function currentId(): ?string
    {
        if (! $this->available() || ! tenancy()->initialized || ! tenancy()->tenant) {
            return null;
        }

        $id = (string) tenancy()->tenant->getTenantKey();

        return $id !== '' ? $id : null;
    }

    /** Initialize tenancy for the tenant with $slug; false when there is no such tenant (or no tenancy). */
    public function enter(string $slug): bool
    {
        if ($slug === '' || ! $this->available()) {
            return false;
        }

        if ($this->currentSlug() === $slug) {
            return true;
        }

        $tenant = (self::TENANT_MODEL)::query()->where('slug', $slug)->first();
        if (! $tenant) {
            return false;
        }

        tenancy()->initialize($tenant);

        return true;
    }

    private function available(): bool
    {
        return config('tenancy.enabled') && function_exists('tenancy') && class_exists(self::TENANT_MODEL);
    }
}
