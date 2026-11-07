<?php

namespace App\Services\Runtimes\Sources;

use App\Models\KnativeRegion;
use App\Models\RuntimeSource;
use App\Services\SupplyChain\CosignVerifier;
use App\Services\SupplyChain\ImageRef;
use App\Support\Images\OciRegistryClient;

/**
 * Reads an OCI runtime catalog (docs/runtimes.md#oci-catalogs), the way the patch feed is read
 * (PatchFeedSync):
 *
 *  1. HEAD of the tag: the manifest digest. Unchanged since the last good read — nothing more to
 *     do; a check costs one HEAD.
 *  2. The manifest by that digest, its one layer of type application/vnd.runr.runtime-catalog.v1+json.
 *  3. With a public key (always for RunR's default catalog): the cosign signature of the manifest
 *     digest verified before a word of the catalog is trusted (CosignVerifier). Patches the
 *     catalog carries count only from a verified catalog.
 *  4. The layer, schema 1 (CatalogPayload).
 *
 * Private registries: the source's registry credentials (registry_host, default the catalog's
 * host). RunR's default catalog can be read through a region's registry (via_region) instead.
 * A refused read without credentials is not_found, not auth_failed (refused()).
 */
class OciCatalogReader
{
    /**
     * @throws RuntimeSourceException
     */
    public function read(RuntimeSource $source, bool $force = false): SourceSnapshot
    {
        $reference = $source->reference();
        if ($reference === '') {
            throw RuntimeSourceException::unreachable('No catalog reference is configured');
        }
        $ref = ImageRef::parse($reference);
        $repository = $ref->name();
        $client = $this->client($source);
        $key = $source->verificationKey();
        if ($source->is_default && $key === null) {
            throw new RuntimeSourceException(RuntimeSource::INVALID_SIGNATURE, 'RunR\'s catalog needs a public key (RUNR_RUNTIME_CATALOG_PUBLIC_KEY)');
        }

        try {
            $digest = $ref->digest ?? $client->manifestDigest($repository, (string) ($ref->tag ?? 'latest'));
        } catch (\Throwable $e) {
            throw RuntimeSourceException::unreachable(SourceCredentials::maskFor($e->getMessage(), $source));
        }
        if ($digest === null) {
            throw $this->refused($client, $source, "The catalog {$ref->canonical()} could not be read");
        }

        if (! $force && $digest === $source->revision && in_array($source->status, [RuntimeSource::OK, RuntimeSource::PARTIAL], true)) {
            return SourceSnapshot::unchanged($digest, $source->signature_verified);
        }

        $manifest = $client->manifest($repository, $digest);
        if ($manifest === null) {
            throw $this->refused($client, $source, "The catalog manifest {$digest} could not be read");
        }
        if (! hash_equals($digest, $manifest['digest'])) {
            throw RuntimeSourceException::invalid('The catalog manifest does not match its digest');
        }

        $layer = collect((array) (json_decode($manifest['body'], true)['layers'] ?? []))
            ->first(fn ($l) => is_array($l) && ($l['mediaType'] ?? null) === CatalogPayload::MEDIA_TYPE);
        if (! $layer || ! is_string($layer['digest'] ?? null)) {
            throw RuntimeSourceException::invalid('The catalog manifest has no '.CatalogPayload::MEDIA_TYPE.' layer');
        }
        $max = (int) config('runtime_sources.max_catalog_bytes', 8 * 1024 * 1024);
        if ((int) ($layer['size'] ?? 0) > $max) {
            throw RuntimeSourceException::invalid("The catalog is larger than {$max} bytes");
        }

        // The signature before the content is trusted
        $verified = false;
        if ($key !== null) {
            $problem = (new CosignVerifier($client))->verify($repository, $digest, $key);
            if ($problem !== null) {
                throw new RuntimeSourceException(RuntimeSource::INVALID_SIGNATURE, $problem);
            }
            $verified = true;
        }

        $body = $client->blob($repository, $layer['digest']);
        if ($body === null) {
            throw $this->refused($client, $source, 'The catalog layer could not be read');
        }

        $data = json_decode($body, true);
        $entries = CatalogPayload::entries($data);

        return new SourceSnapshot($digest, $entries, $verified ? CatalogPayload::patches($data) : null, $verified);
    }

    /** The registry client of a source: its credentials, or RunR's catalog through a region. */
    public function client(RuntimeSource $source): OciRegistryClient
    {
        $timeout = max(1, (int) config('runtime_sources.timeout', 20));

        if ($source->is_default) {
            $via = trim((string) config('runtime_sources.default.via_region'));
            if ($via !== '') {
                $region = KnativeRegion::query()->whereKey($via)->first() ?? KnativeRegion::query()->where('name', $via)->first();
                if (! $region) {
                    throw RuntimeSourceException::unreachable("Region {$via} (RUNR_RUNTIME_CATALOG_VIA_REGION) not found");
                }

                return OciRegistryClient::viaRegion($region, $timeout);
            }
        }

        $client = OciRegistryClient::direct($timeout);
        if ($source->hasRegistryCredentials()) {
            $host = $source->registry_host ?: ImageRef::parse($source->reference())->registry;

            return $client->withCredentials($host, (string) $source->credential('registry', 'username'), (string) $source->credential('registry', 'password'));
        }

        return $client;
    }

    /**
     * Why the registry did not give what was asked:
     *  - 401 / 403 to the source's credentials: auth_failed (the credentials were refused);
     *  - 401 / 403 to a read without credentials, or 404: not_found — an anonymous reader cannot
     *    tell a missing package from a private one (ghcr.io answers both with 401 / 403);
     *  - anything else: unreachable.
     */
    private function refused(OciRegistryClient $client, RuntimeSource $source, string $message): RuntimeSourceException
    {
        $status = $client->lastStatus();
        if (in_array($status, [401, 403], true) && $this->sendsCredentials($source)) {
            return new RuntimeSourceException(RuntimeSource::AUTH_FAILED, "{$message}: the registry refused the credentials (HTTP {$status})");
        }
        if (in_array($status, [401, 403], true)) {
            return new RuntimeSourceException(RuntimeSource::NOT_FOUND, "{$message}: the registry has no such catalog, or it is not public (HTTP {$status}); a private catalog needs registry credentials");
        }
        if ($status === 404) {
            return new RuntimeSourceException(RuntimeSource::NOT_FOUND, "{$message}: the registry has no such catalog (HTTP 404)");
        }

        return RuntimeSourceException::unreachable($message.($status ? " (HTTP {$status})" : ''));
    }

    /**
     * Whether the catalog is read with the source's registry credentials: they are set and are
     * for the catalog's registry (registry_host, default the catalog's host). RunR's catalog is
     * read without any.
     */
    private function sendsCredentials(RuntimeSource $source): bool
    {
        if ($source->is_default || ! $source->hasRegistryCredentials()) {
            return false;
        }
        $catalogHost = ImageRef::parse($source->reference())->registry;
        $host = $source->registry_host ?: $catalogHost;

        return OciRegistryClient::normalizeHost($host) === OciRegistryClient::normalizeHost($catalogHost);
    }
}
