<?php

namespace App\Services\SupplyChain;

/**
 * Container image references the way registries and the admission controller see them:
 * "nginx:1.27" is docker.io/library/nginx:1.27, "10.43.0.5:5000/runr-builds/api:v3" keeps its
 * registry host (a port marks a host), a digest wins over a tag.
 */
final class ImageRef
{
    private function __construct(
        public readonly string $registry,
        public readonly string $repository,
        public readonly ?string $tag,
        public readonly ?string $digest,
    ) {}

    public static function parse(string $ref): self
    {
        $ref = trim($ref);
        $digest = null;
        if (str_contains($ref, '@')) {
            [$ref, $digest] = explode('@', $ref, 2);
        }

        $tag = null;
        $lastSlash = strrpos($ref, '/');
        $lastColon = strrpos($ref, ':');
        if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
            $tag = substr($ref, $lastColon + 1);
            $ref = substr($ref, 0, $lastColon);
        }

        $first = explode('/', $ref, 2)[0];
        if (str_contains($ref, '/') && (str_contains($first, '.') || str_contains($first, ':') || $first === 'localhost')) {
            [$registry, $repository] = explode('/', $ref, 2);
        } else {
            $registry = 'docker.io';
            $repository = str_contains($ref, '/') ? $ref : "library/{$ref}";
        }

        if (in_array($registry, ['index.docker.io', 'registry-1.docker.io'], true)) {
            $registry = 'docker.io';
        }

        return new self($registry, $repository, $digest === null ? ($tag ?? 'latest') : $tag, $digest);
    }

    /** Fully qualified reference ("docker.io/library/nginx:1.27", digest when known). */
    public function canonical(): string
    {
        $base = "{$this->registry}/{$this->repository}";

        return $this->digest ? "{$base}@{$this->digest}" : "{$base}:{$this->tag}";
    }

    /** The same image pinned to a digest. */
    public function withDigest(string $digest): string
    {
        return "{$this->registry}/{$this->repository}@{$digest}";
    }

    /** Repository with its registry, without tag / digest. */
    public function name(): string
    {
        return "{$this->registry}/{$this->repository}";
    }

    /**
     * Repository as policy-controller matches it (Docker Hub images are index.docker.io/...).
     */
    public function policyName(): string
    {
        $registry = $this->registry === 'docker.io' ? 'index.docker.io' : $this->registry;

        return "{$registry}/{$this->repository}";
    }

    /**
     * Glob(s) of a ClusterImagePolicy matching exactly this repository, by tag or by digest
     * (Knative and the webhook pin tags to digests).
     *
     * @return list<string>
     */
    public function policyGlobs(): array
    {
        return [$this->policyName().':*', $this->policyName().'@*'];
    }
}
