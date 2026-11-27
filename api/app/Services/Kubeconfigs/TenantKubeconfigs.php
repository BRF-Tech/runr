<?php

namespace App\Services\Kubeconfigs;

use App\Models\KnativeRegion;
use App\Services\Dns\DnsResolver;
use App\Services\KubernetesClient;
use App\Services\Observability\OutboundUrlGuard;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The kubeconfigs of organizations' own regions (docs/security.md, "Tenant region kubeconfigs").
 *
 * A region an organization owns (restricts(): created in a tenant's context, owner_tenant_id /
 * is_synced = false) has a kubeconfig its owner wrote. RunR stores it only as KubeconfigPolicy
 * accepts it (forStorage()) and uses it only through forUse(): the policy again — a kubeconfig
 * stored by an earlier release is held to it on every read — and the server's address checked
 * by OutboundUrlGuard (https, public addresses only) and pinned (KubeconfigPin), so neither a
 * name that answers another address later (DNS rebinding) nor a redirect of the server leads
 * RunR anywhere else. tenancy.region_kubeconfig.allow_private_servers (TENANCY_REGIONS_ALLOW_PRIVATE)
 * accepts private addresses of organizations' clusters as well — never loopback, link-local
 * (cloud metadata), unspecified or multicast ones — still pinned.
 *
 * Platform regions (created by a platform administrator, or an installation without tenancy)
 * keep their kubeconfig as it is: a credential plugin there is the operator's own choice.
 *
 * The check of a kubeconfig is remembered for CACHE_SECONDS (the pinned addresses were checked:
 * using them a minute longer reaches nothing new); a singleton keeps it for the process.
 */
#[Singleton]
class TenantKubeconfigs
{
    private const CACHE_SECONDS = 60;

    /** The text of a connection's error: curl's, a dial's, a TLS handshake's, helm's or kubectl's */
    private const CONNECTION_TEXT = '~cURL error \d+|dial tcp|connection refused|i/o timeout|no such host|context deadline exceeded|tls: |x509: |Kubernetes cluster unreachable|Unable to connect to the server~i';

    private const CACHE_ENTRIES = 128;

    /** @var array<string, array{until: int, yaml?: string, refused?: KubeconfigRefused}> */
    private array $checked = [];

    /** @var array<string, true> refusals already logged, by region and reason */
    private array $logged = [];

    public function __construct(private readonly OutboundUrlGuard $guard, private readonly DnsResolver $dns) {}

    /** The region is an organization's own: its kubeconfig is held to the policy. */
    public static function restricts(KnativeRegion $region): bool
    {
        $attributes = $region->getAttributes();
        if (! empty($attributes['owner_tenant_id'])) {
            return true;
        }

        return array_key_exists('is_synced', $attributes) && ! (bool) $attributes['is_synced'];
    }

    /**
     * A region created now is an organization's own: a tenant's context is active (the region
     * API marks such a region tenant-owned, TenantAwareKnativeRegion::markTenantOwned()).
     */
    public static function restrictsNewRegions(): bool
    {
        return function_exists('tenancy') && tenancy()->initialized;
    }

    /** Private (not loopback / link-local) addresses of organizations' API servers are accepted. */
    public static function allowsPrivateServers(): bool
    {
        return (bool) config('tenancy.region_kubeconfig.allow_private_servers', false);
    }

    /**
     * Whether RunR may connect to $ip for an organization's region (an address its cluster
     * reports, e.g. GlobalFailover's health check of its Traefik): a public address, or with
     * allowsPrivateServers() a private one — never loopback, link-local, unspecified or multicast.
     */
    public static function mayReach(string $ip): bool
    {
        if (OutboundUrlGuard::isPublicIp($ip)) {
            return true;
        }

        return self::allowsPrivateServers() && ! self::neverAServer($ip);
    }

    /**
     * Whether the user sees what a connection to an organization's cluster failed with: platform
     * administrators do, and everyone for a platform region. An organization's users see a short
     * reason (connectionError()): the details of a failed connection would tell them about
     * addresses and services they cannot reach otherwise.
     */
    public static function showsDetails(bool $restricted): bool
    {
        return ! $restricted || (bool) (auth()->user()?->getAttribute('is_admin') ?? false);
    }

    /**
     * The kubeconfig as an organization's region stores it.
     *
     * @throws KubeconfigRefused
     */
    public function forStorage(string $kubeconfig): string
    {
        return KubeconfigPolicy::normalize($kubeconfig)->yaml();
    }

    /**
     * The kubeconfig an organization's region connects with: the policy's, its server checked and
     * pinned.
     *
     * @throws KubeconfigRefused
     */
    public function forUse(string $kubeconfig): string
    {
        $key = hash('sha256', $kubeconfig).(self::allowsPrivateServers() ? ':private' : '');
        $now = time();
        $hit = $this->checked[$key] ?? null;
        if ($hit !== null && $hit['until'] > $now) {
            if (isset($hit['refused'])) {
                throw $hit['refused'];
            }

            return (string) $hit['yaml'];
        }

        if (count($this->checked) >= self::CACHE_ENTRIES) {
            $this->checked = array_filter($this->checked, fn (array $entry) => $entry['until'] > $now);
            if (count($this->checked) >= self::CACHE_ENTRIES) {
                $this->checked = [];
            }
        }

        try {
            $normalized = KubeconfigPolicy::normalize($kubeconfig);
            $yaml = $normalized->pinnedYaml($this->addresses($normalized));
        } catch (KubeconfigRefused $e) {
            $this->checked[$key] = ['until' => $now + self::CACHE_SECONDS, 'refused' => $e];

            throw $e;
        }
        $this->checked[$key] = ['until' => $now + self::CACHE_SECONDS, 'yaml' => $yaml];

        return $yaml;
    }

    /**
     * A kubeconfig a request sent: the one to store and the one to connect with. A platform
     * region's is adapted for a local cluster (KubernetesClient::adaptKubeconfig(), both the
     * same); an organization's region's is the policy's rebuild and its pinned copy — a 422 on
     * $field when it is refused.
     *
     * @return array{string, string}
     *
     * @throws ValidationException
     */
    public function prepare(string $kubeconfig, bool $restricted, string $field = 'kubeconfig'): array
    {
        if (! $restricted) {
            $adapted = KubernetesClient::adaptKubeconfig($kubeconfig);

            return [$adapted, $adapted];
        }

        try {
            return [$this->forStorage($kubeconfig), $this->forUse($kubeconfig)];
        } catch (KubeconfigRefused $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }

    /**
     * The kubeconfig of an organization's region to connect with, null when it is refused (the
     * refusal is logged once per process; refusal() gives the reason).
     */
    public function usable(KnativeRegion $region, string $stored): ?string
    {
        try {
            return $this->forUse($stored);
        } catch (KubeconfigRefused $e) {
            $key = $region->getKey().':'.$e->reason;
            if (! isset($this->logged[$key])) {
                $this->logged[$key] = true;
                Log::warning('The kubeconfig of an organization\'s region is not used', ['region' => $region->getKey(), 'reason' => $e->getMessage()]);
            }

            return null;
        }
    }

    /**
     * Why RunR does not use the region's kubeconfig (translated, regions.kubeconfig.unusable),
     * null when it does — or the region is a platform region, a demo or has none.
     */
    public function refusal(KnativeRegion $region): ?string
    {
        // an organization's own region keeps its kubeconfig in its own row: no database is read
        if (! self::restricts($region) || $region->is_demo) {
            return null;
        }
        $stored = $region->storedKubeconfig();
        if ($stored === null || $stored === '' || $stored === 'demo') {
            return null;
        }

        try {
            $this->forUse($stored);

            return null;
        } catch (KubeconfigRefused $e) {
            return (string) __('regions.kubeconfig.unusable', ['reason' => $e->getMessage()]);
        }
    }

    /**
     * The short reason an organization's user sees for a failed request to a cluster; the
     * exception's own message when showsDetails().
     */
    public static function connectionError(Throwable $e, bool $details): string
    {
        if ($details || $e instanceof KubeconfigRefused) {
            return $e->getMessage();
        }
        if ($e instanceof ConnectionException) {
            return (string) __('regions.kubeconfig.unreachable');
        }
        if ($e instanceof RequestException) {
            return self::responseError($e->response);
        }

        return (string) __('regions.kubeconfig.failed');
    }

    /**
     * What the current user reads of $e, an error of a request to $region's cluster: the message
     * itself for a platform region and for platform administrators; for an organization's own
     * region its users read the short reason (connectionError()) of a failed connection or answer
     * — also when another exception carries it, as its previous exception or in its text — and any
     * other message (a refusal of RunR's own, a missing component) as it is.
     */
    public static function messageFor(Throwable $e, ?KnativeRegion $region): string
    {
        if ($region === null || self::showsDetails(self::restricts($region))) {
            return $e->getMessage();
        }

        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectionException || $cause instanceof RequestException || $cause instanceof KubeconfigRefused) {
                return self::connectionError($cause, false);
            }
        }

        return self::textFor($e->getMessage(), true);
    }

    /**
     * A message RunR stored about a cluster (an install's or a setup's, written by a job) as the
     * current user reads it: for an organization's own region ($restricted) its users read the
     * short reason instead of the text of a connection's error (curl's, a dial's, helm's or
     * kubectl's), which names the addresses RunR connected to; anything else as it is.
     */
    public static function textFor(?string $text, bool $restricted): ?string
    {
        if ($text === null || $text === '' || self::showsDetails($restricted)) {
            return $text;
        }

        return preg_match(self::CONNECTION_TEXT, $text) ? (string) __('regions.kubeconfig.unreachable') : $text;
    }

    /** The short reason of a cluster's answer that is no success. */
    public static function responseError(Response $response): string
    {
        $status = $response->status();

        return in_array($status, [401, 403], true)
            ? (string) __('regions.kubeconfig.rejected', ['status' => $status])
            : (string) __('regions.kubeconfig.unexpected', ['status' => $status]);
    }

    /**
     * The addresses RunR connects to for the kubeconfig's server: those OutboundUrlGuard checked
     * (a literal address: itself).
     *
     * @return list<string>
     */
    private function addresses(TenantKubeconfig $kubeconfig): array
    {
        $target = $this->guard->target($kubeconfig->serverUrl(), false, true);
        if ($target->allowed()) {
            $literal = $kubeconfig->literalAddress();

            return $target->addresses !== [] ? $target->addresses : ($literal !== null ? [$literal] : $this->refuse($kubeconfig, 'unresolvable'));
        }
        if ($target->code !== 'private' || ! self::allowsPrivateServers()) {
            $this->refuse($kubeconfig, (string) $target->code);
        }

        // Private cluster addresses accepted: every address the name answers, none of them one
        // that never is a cluster's (loopback, link-local, unspecified, multicast, broadcast)
        $literal = $kubeconfig->literalAddress();
        $addresses = $literal !== null ? [$literal] : array_values(array_unique($this->dns->addresses($kubeconfig->host)));
        if ($addresses === []) {
            $this->refuse($kubeconfig, 'unresolvable');
        }
        foreach ($addresses as $ip) {
            if (self::neverAServer($ip)) {
                $this->refuse($kubeconfig, 'private');
            }
        }

        return $addresses;
    }

    private function refuse(TenantKubeconfig $kubeconfig, string $code): never
    {
        throw new KubeconfigRefused('server_blocked', ['host' => $kubeconfig->host, 'reason' => (string) __('observability.url.'.$code)]);
    }

    /** Loopback, link-local, unspecified, multicast, reserved or broadcast (IPv4 inside IPv6 too). */
    private static function neverAServer(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        $ipv4 = OutboundUrlGuard::embeddedIpv4($ip) ?? (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ip : null);
        if ($ipv4 !== null) {
            foreach (['0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '240.0.0.0/4'] as $cidr) {
                if (self::inCidr($ipv4, $cidr)) {
                    return true;
                }
            }

            return false;
        }
        foreach (['::/128', '::1/128', 'fe80::/10', 'fec0::/10', 'ff00::/8'] as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
