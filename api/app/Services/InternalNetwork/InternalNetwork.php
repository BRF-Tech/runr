<?php

namespace App\Services\InternalNetwork;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Setting;
use App\Support\TenantAccess;
use Illuminate\Support\Str;

/**
 * The region's internal network (docs/internal-network.md): how a project calls another one inside
 * the cluster, and who may.
 *
 *  - Names. Every project has an internal name <project>.<organization> and a host
 *    <project>.<organization>.runr.internal (CoreDNS sends runr.internal to the region Traefik's
 *    private entry point, an IngressRoute per Knative Service routes the host). The organization
 *    is the tenant's slug, "platform" for the projects outside any tenant.
 *  - Callers. Every workload of a project gets RUNR_INTERNAL_TOKEN: its internal name signed with
 *    the region's key (HMAC-SHA256). A call sends it in X-Runr-Internal-Token; the ForwardAuth of
 *    the private entry point (the region agent in authz mode) checks it and the callee's allow
 *    list, then removes the header. A call without a token is its project's when it comes from
 *    a pod RunR runs for the project (label runr/project-id, its address): workloads deployed
 *    before the internal network, queue consumers and functions that call their bound model
 *    service without a header. RunR's own calls through the API server (shell, model
 *    playground) sign a short-lived token for runr:system.
 *  - Allow lists. A project accepts calls from itself, from runr:system and from the projects on
 *    its allow list (ProjectInternalCaller); a project of another organization needs a grant on
 *    that list, which the platform can switch off (crossTenantGrantsEnabled()).
 */
final class InternalNetwork
{
    public const DOMAIN = 'runr.internal';

    /** Organization label of the projects outside any tenant */
    public const PLATFORM_ORG = 'platform';

    public const HEADER = 'X-Runr-Internal-Token';

    /** Verified caller, set by the ForwardAuth for the callee */
    public const CALLER_HEADER = 'X-Runr-Caller';

    public const ENV_TOKEN = 'RUNR_INTERNAL_TOKEN';

    public const ENV_NAME = 'RUNR_INTERNAL_NAME';

    public const SYSTEM_SUBJECT = 'runr:system';

    public const TOKEN_PREFIX = 'rit1';

    /** Seconds a runr:system token is valid */
    public const SYSTEM_TOKEN_TTL = 120;

    /** <project>.<organization>, both DNS labels */
    public const NAME_PATTERN = '/^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?\.[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?$/';

    /** Platform setting: may a project allow callers of another organization */
    public const CROSS_TENANT_SETTING = 'internal_cross_tenant_grants';

    /** Region setting: enforce (deny what no allow list admits, the default) or audit (log, admit) */
    public const MODE_SETTING = 'internal_auth';

    /** Label of a workload's pods: the internal name of their project (a label value: 63 characters at most) */
    public const POD_LABEL = 'runr.dev/internal-name';

    public const MODE_ENFORCE = 'enforce';

    public const MODE_AUDIT = 'audit';

    // ── Names ───────────────────────────────────────────────────────────────

    /** A tenant slug as a DNS label; null (no tenant) is the platform's organization */
    public static function orgLabel(?string $tenantSlug): string
    {
        if ($tenantSlug === null || $tenantSlug === '') {
            return self::PLATFORM_ORG;
        }
        $label = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($tenantSlug)), '-');

        return $label === '' ? self::PLATFORM_ORG : substr($label, 0, 63);
    }

    /** The organization of the current context (the tenant, or the platform's) */
    public static function currentOrg(): string
    {
        return self::orgLabel(TenantAccess::current()['slug'] ?? null);
    }

    /**
     * The project part of an internal name: its Knative Service name without the tenant suffix
     * the Tenancy module appends (<name>-<tenant>).
     */
    public static function projectLabel(string $serviceName, ?string $tenantSlug): string
    {
        if ($tenantSlug !== null && $tenantSlug !== '' && str_ends_with($serviceName, '-'.$tenantSlug)) {
            $base = substr($serviceName, 0, -strlen('-'.$tenantSlug));

            return $base !== '' ? $base : $serviceName;
        }

        return $serviceName;
    }

    /** <project>.<organization> of a Knative Service, from its name and runr/tenant label */
    public static function nameFor(string $serviceName, ?string $tenantSlug): string
    {
        return self::projectLabel($serviceName, $tenantSlug).'.'.self::orgLabel($tenantSlug);
    }

    /** <project>.<organization> of a project of the current context */
    public static function nameOf(Project $project): string
    {
        return self::nameFor($project->slug, TenantAccess::current()['slug'] ?? null);
    }

    public static function hostOf(Project $project): string
    {
        return self::nameOf($project).'.'.self::DOMAIN;
    }

    public static function hostFor(string $name): string
    {
        return $name.'.'.self::DOMAIN;
    }

    public static function orgOf(string $name): string
    {
        return (string) substr($name, strpos($name, '.') + 1);
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    // ── Settings ────────────────────────────────────────────────────────────

    /** May a project allow callers of another organization (platform setting, default on) */
    public static function crossTenantGrantsEnabled(): bool
    {
        return Setting::platform(self::CROSS_TENANT_SETTING, 'true') !== 'false';
    }

    /**
     * enforce or audit. Every region enforces — a new one and one upgraded from a release before
     * the internal network alike; audit (calls admitted, the denials logged) is an operator's
     * explicit choice to look first.
     */
    public static function modeOf(KnativeRegion $region): string
    {
        return ($region->effective_settings[self::MODE_SETTING] ?? null) === self::MODE_AUDIT ? self::MODE_AUDIT : self::MODE_ENFORCE;
    }

    /** @return array<string, string> the pod label of a project's workloads (none for a name too long for a label value) */
    public static function podLabels(Project $project): array
    {
        $name = self::nameOf($project);

        return strlen($name) <= 63 ? [self::POD_LABEL => $name] : [];
    }

    // ── Tokens ──────────────────────────────────────────────────────────────

    /** The region's signing key (32 bytes), derived from the application key and the region id */
    public static function regionKey(string $regionId): string
    {
        $appKey = (string) config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        return hash_hmac('sha256', 'runr-internal-network|'.$regionId, $appKey, true);
    }

    /** RUNR_INTERNAL_TOKEN of a project's workloads: its internal name, signed for its region */
    public static function tokenFor(string $regionId, string $name): string
    {
        return self::sign($regionId, ['sub' => $name, 'reg' => $regionId, 'iat' => time()]);
    }

    /** A short-lived token of RunR itself (shell keep-alive, model playground) */
    public static function systemToken(string $regionId): string
    {
        $now = time();

        return self::sign($regionId, ['sub' => self::SYSTEM_SUBJECT, 'reg' => $regionId, 'iat' => $now, 'exp' => $now + self::SYSTEM_TOKEN_TTL]);
    }

    /** @param array<string, mixed> $claims */
    public static function sign(string $regionId, array $claims): string
    {
        $payload = self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $signed = self::TOKEN_PREFIX.'.'.$payload;

        return $signed.'.'.self::b64(hash_hmac('sha256', $signed, self::regionKey($regionId), true));
    }

    /**
     * The claims of a token signed for $regionId, or null when it is malformed, signed with
     * another key or expired (the region agent checks the same, runtime/agent/internal/authz).
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $regionId, string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::TOKEN_PREFIX) {
            return null;
        }
        $expected = self::b64(hash_hmac('sha256', $parts[0].'.'.$parts[1], self::regionKey($regionId), true));
        if (! hash_equals($expected, $parts[2])) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        if (! is_array($claims) || ! is_string($claims['sub'] ?? null)) {
            return null;
        }
        if (isset($claims['exp']) && (int) $claims['exp'] < ($now ?? time())) {
            return null;
        }

        return $claims;
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** A caller name as typed (case and surrounding blanks do not count) */
    public static function normalizeName(string $name): string
    {
        return Str::lower(trim($name));
    }
}
