<?php

namespace App\Services\ApiProduct;

use App\Models\ApiProduct\GatewayAuthScope;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Tells the regions' gateway-auth services that something they enforce changed: the revision of
 * the current organization's row for the region (gateway_auth_scopes, central) moves, so the
 * service's next sync (ETag) gets the new configuration. A gateway with key / JWT routes registers
 * its region on every sync (GatewayService); a change without a region (an organization plan or
 * consumer) moves every region of the organization.
 */
class GatewayAuthRevision
{
    public static function scope(): string
    {
        return RuntimeRegistry::currentTenantId() ?? GatewayAuthScope::CENTRAL;
    }

    /** The organization has routes behind the region's gateway-auth (idempotent). */
    public function register(string $regionId): void
    {
        $this->guard(function () use ($regionId) {
            GatewayAuthScope::query()->firstOrCreate(
                ['knative_region_id' => $regionId, 'scope' => self::scope()],
                ['revision' => 1],
            );
        });
    }

    /** $regionId null: every region of the organization. */
    public function bump(?string $regionId = null): void
    {
        $this->guard(function () use ($regionId) {
            $rows = GatewayAuthScope::query()->where('scope', self::scope());
            if ($regionId !== null) {
                $rows->where('knative_region_id', $regionId);
            }
            $rows->increment('revision');
        });
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            // the next change (or the ten-minute refresh of GatewayAuthConfig's ETag) catches up
            Log::warning('Gateway auth revision not updated', ['error' => $e->getMessage()]);
        }
    }
}
