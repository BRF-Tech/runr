<?php

namespace App\Rules;

use App\Models\KnativeRegion;
use App\Services\Kubeconfigs\KubeconfigRefused;
use App\Services\Kubeconfigs\TenantKubeconfigs;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The kubeconfig of a region an organization owns (TenantKubeconfigs): what KubeconfigPolicy
 * accepts, with a server at an address RunR may reach — refused with the reason
 * (regions.kubeconfig.*). A platform region's kubeconfig passes as it is.
 */
final class RegionKubeconfig implements ValidationRule
{
    private function __construct(private readonly bool $restricted) {}

    /** A kubeconfig for a region created now (an organization's in a tenant's context). */
    public static function forNewRegion(): self
    {
        return new self(TenantKubeconfigs::restrictsNewRegions());
    }

    /** A kubeconfig for $region (null: as for a new one). */
    public static function forRegion(?KnativeRegion $region): self
    {
        return new self($region instanceof KnativeRegion ? TenantKubeconfigs::restricts($region) : TenantKubeconfigs::restrictsNewRegions());
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->restricted || ! is_string($value)) {
            return;
        }

        try {
            app(TenantKubeconfigs::class)->forUse($value);
        } catch (KubeconfigRefused $e) {
            $fail($e->getMessage());
        }
    }
}
