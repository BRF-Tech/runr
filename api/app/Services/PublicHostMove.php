<?php

namespace App\Services;

/**
 * A domain move that has just completed (DomainMigrateService, TenantDomainService): the Knative
 * Services whose public host was <service>.$from answer on <service>.$to from now on — or will in
 * a moment, once Knative has reconciled their routes. RunR's rewrites to those hosts (custom
 * domains, gateway routes: PublicHostRewrites) follow at once instead of waiting until the
 * services' status names the new host.
 *
 * $owner: an organization's move on a platform region covers only its own services — named
 * <…>-<organization slug>, preview services <…>-<organization slug>-pr-<n> (Project::slugFor) —
 * as the organization's domain alias does (RegionDomainAliasService).
 */
final class PublicHostMove
{
    public readonly string $from;

    public readonly string $to;

    public function __construct(string $from, string $to, public readonly ?string $owner = null)
    {
        $this->from = strtolower(trim($from, '.'));
        $this->to = strtolower(trim($to, '.'));
    }

    /** Whether the move covers Knative Service $service. */
    public function owns(string $service): bool
    {
        if ($this->owner === null || $this->owner === '') {
            return true;
        }

        return str_ends_with((string) preg_replace('/-pr-[0-9]+$/', '', $service), '-'.$this->owner);
    }

    /** $host of Knative Service $service after the move ($host itself when the move does not cover it). */
    public function map(string $service, string $host): string
    {
        $host = strtolower($host);
        if ($this->from === '' || $this->to === '' || $this->from === $this->to || ! $this->owns($service)) {
            return $host;
        }

        $suffix = '.'.$this->from;
        if (! str_ends_with($host, $suffix)) {
            return $host;
        }

        return substr($host, 0, -strlen($suffix)).'.'.$this->to;
    }
}
