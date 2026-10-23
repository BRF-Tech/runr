<?php

namespace App\Services\Secrets;

use App\Models\ProjectSecret;
use Illuminate\Support\Collection;

/**
 * The secret backends by source (project_secrets.source). Registered once (SecurityServiceProvider):
 * "region", "external" and "openbao"; a later backend — per-environment stores — registers itself
 * here and every deploy, teardown, reveal and region move goes through it.
 */
class SecretBackends
{
    /** @var array<string, SecretBackend> */
    private array $backends = [];

    public function __construct()
    {
        $this->register(new RegionSecretBackend);
        $this->register(new ExternalSecretBackend);
        $this->register(new OpenBaoSecretBackend);
    }

    /** Add (or replace) the backend of its source. */
    public function register(SecretBackend $backend): void
    {
        $this->backends[$backend->source()] = $backend;
    }

    /** @throws \InvalidArgumentException an unknown source */
    public function for(ProjectSecret|string $source): SecretBackend
    {
        $key = $source instanceof ProjectSecret ? ($source->source ?: ProjectSecret::SOURCE_REGION) : $source;

        return $this->backends[$key] ?? throw new \InvalidArgumentException("No secret backend for source {$key}");
    }

    /** @return array<string, SecretBackend> */
    public function all(): array
    {
        return $this->backends;
    }

    /** @return list<string> */
    public function sources(): array
    {
        return array_keys($this->backends);
    }

    /**
     * The variables of each backend, every backend present (an empty collection when it holds none).
     *
     * @param  Collection<int, ProjectSecret>  $secrets
     * @return array<string, Collection<int, ProjectSecret>>
     */
    public function partition(Collection $secrets): array
    {
        $parts = [];
        foreach ($this->backends as $source => $backend) {
            $parts[$source] = $secrets->filter(fn (ProjectSecret $s) => ($s->source ?: ProjectSecret::SOURCE_REGION) === $source)->values();
        }

        return $parts;
    }
}
