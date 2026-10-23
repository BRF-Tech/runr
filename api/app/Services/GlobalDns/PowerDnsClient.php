<?php

namespace App\Services\GlobalDns;

use App\Services\KubernetesClient;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * PowerDNS Authoritative HTTP API of a region's server (/api/v1/servers/localhost/…), reached
 * through the Kubernetes API server's service proxy of Service runr-pdns-api — never over the
 * network: the API is not exposed outside the cluster. Every call carries the server's API key.
 *
 * Names are absolute (trailing dot) as the API wants them; record contents are the zone-file
 * presentation (an rrset of type LUA holds "A \"ifurlup(…)\"" contents).
 */
class PowerDnsClient
{
    private const SERVER = 'api/v1/servers/localhost';

    public function __construct(
        private readonly KubernetesClient $k8s,
        private readonly string $namespace,
        private readonly string $apiKey,
    ) {}

    /** The zone with its rrsets, or null when the server has no such zone. */
    public function zone(string $zone): ?array
    {
        $response = $this->call('GET', self::SERVER.'/zones/'.rawurlencode($zone));
        if ($response->status() === 404 || $response->status() === 422) {
            return null;
        }
        $this->assertOk($response, "read zone {$zone}");

        return (array) $response->json();
    }

    /**
     * Create a native zone (no AXFR replication: RunR writes every server itself).
     *
     * @param  list<string>  $nameservers  absolute names
     */
    public function createZone(string $zone, array $nameservers): void
    {
        $response = $this->call('POST', self::SERVER.'/zones', [
            'name' => $zone,
            'kind' => 'Native',
            'nameservers' => $nameservers,
            // the serial follows every API change (PowerDNS' DEFAULT soa-edit-api)
            'soa_edit_api' => 'DEFAULT',
        ]);
        $this->assertOk($response, "create zone {$zone}");
    }

    /**
     * Apply rrset changes in one PATCH: each ['name', 'type', 'ttl', 'changetype' => REPLACE|DELETE,
     * 'records' => [['content' => …, 'disabled' => false]]].
     *
     * @param  list<array<string, mixed>>  $rrsets
     */
    public function patchRrsets(string $zone, array $rrsets): void
    {
        if ($rrsets === []) {
            return;
        }

        $response = $this->call('PATCH', self::SERVER.'/zones/'.rawurlencode($zone), ['rrsets' => $rrsets]);
        $this->assertOk($response, "update zone {$zone}");
    }

    /**
     * The TSIG key of the DNS-01 updates: created when missing, its secret replaced when different.
     */
    public function ensureTsigKey(string $name, string $algorithm, string $secret): void
    {
        $id = rtrim($name, '.').'.';
        $response = $this->call('GET', self::SERVER.'/tsigkeys/'.rawurlencode($id));

        if ($response->successful()) {
            if ((string) $response->json('key') === $secret && strtolower((string) $response->json('algorithm')) === $algorithm) {
                return;
            }
            $this->assertOk($this->call('PUT', self::SERVER.'/tsigkeys/'.rawurlencode($id), [
                'name' => rtrim($name, '.'), 'algorithm' => $algorithm, 'key' => $secret,
            ]), "update TSIG key {$name}");

            return;
        }

        $this->assertOk($this->call('POST', self::SERVER.'/tsigkeys', [
            'name' => rtrim($name, '.'), 'algorithm' => $algorithm, 'key' => $secret,
        ]), "create TSIG key {$name}");
    }

    /** Whether the API answers (the server runs and the key is right). */
    public function ping(): bool
    {
        try {
            return $this->call('GET', self::SERVER)->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function call(string $method, string $path, ?array $body = null): Response
    {
        $headers = ['X-API-Key' => $this->apiKey, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->k8s->serviceProxySend(
            $method, $this->namespace, PowerDnsManifests::API_SERVICE, PowerDnsManifests::API_PORT, $path,
            $headers, $body !== null ? (string) json_encode($body, JSON_UNESCAPED_SLASHES) : null, 20,
        );
    }

    private function assertOk(Response $response, string $what): void
    {
        if ($response->successful()) {
            return;
        }

        $error = $response->json('error') ?? mb_substr(trim($response->body()), 0, 300);

        throw new RuntimeException("PowerDNS API: {$what}: HTTP {$response->status()}".($error !== '' ? ": {$error}" : ''));
    }
}
