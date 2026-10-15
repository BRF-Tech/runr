<?php

namespace App\Support;

/**
 * Pluggable "may this context start new workloads?" check, consulted right before a queued
 * deploy or build touches the cluster (KnativeDeployService::deploy, BuildVersionJob).
 *
 * The base app never blocks. The Tenancy module registers a resolver that blocks while the
 * current tenant is being deleted, so a deploy or Kaniko build queued before the delete started
 * cannot recreate workloads behind the teardown.
 */
class DeployGate
{
    private static ?\Closure $resolver = null;

    public static function setResolver(?\Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @return string|null why new workloads are refused, null when they are allowed
     */
    public static function blockedReason(): ?string
    {
        return self::$resolver ? (self::$resolver)() : null;
    }
}
