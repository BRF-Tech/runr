<?php

namespace Tests\Concerns;

use App\Models\KnativeRegion;
use App\Services\Secrets\RegionSecrets;
use App\Services\Secrets\SecretBackendException;
use App\Services\Secrets\SecretScope;
use Symfony\Component\Yaml\Yaml;

/** In-memory region Secrets (see FakesRegionSecrets). */
class FakeRegionSecrets extends RegionSecrets
{
    /** @var array<string, array{data: array<string, string>, labels: array}> */
    public array $secrets = [];

    /** Every call fails as if the cluster did not answer. */
    public bool $unreachable = false;

    /** @var list<string> "read|write|delete <server> <namespace>/<name>" */
    public array $calls = [];

    public function readFrom(string $kubeconfig, string $namespace, string $name, ?string $region = null): ?array
    {
        $this->calls[] = 'read '.self::key($kubeconfig, $namespace, $name);
        $this->failIfUnreachable($region);

        return $this->secrets[self::key($kubeconfig, $namespace, $name)]['data'] ?? null;
    }

    public function writeTo(string $kubeconfig, string $namespace, string $name, array $values, array $remove = [], array $labels = [], array $annotations = [], ?string $region = null): void
    {
        if ($values === [] && $remove === []) {
            return;
        }
        $this->calls[] = 'write '.self::key($kubeconfig, $namespace, $name);
        $this->failIfUnreachable($region);

        $key = self::key($kubeconfig, $namespace, $name);
        if (! isset($this->secrets[$key])) {
            if ($values === []) {
                return;
            }
            $this->secrets[$key] = ['data' => [], 'labels' => $labels];
        }
        foreach ($values as $k => $v) {
            $this->secrets[$key]['data'][(string) $k] = (string) $v;
        }
        foreach ($remove as $k) {
            if (! array_key_exists($k, $values)) {
                unset($this->secrets[$key]['data'][$k]);
            }
        }
    }

    public function delete(SecretScope $scope): void
    {
        $key = self::key($scope->kubeconfig(), $scope->namespace, $scope->secretName);
        $this->calls[] = 'delete '.$key;
        $this->failIfUnreachable($scope->region?->name);

        unset($this->secrets[$key]);
    }

    /** What the region's cluster holds in a Secret of its project namespace (null: no Secret). */
    public function values(KnativeRegion $region, string $secretName): ?array
    {
        return $this->secrets[self::key((string) $region->kubeconfig, (string) ($region->project_namespace ?? $region->name), $secretName)]['data'] ?? null;
    }

    /** @param array<string, string> $values put straight into the "cluster" (e.g. a value changed there by hand) */
    public function seed(KnativeRegion $region, string $secretName, array $values): void
    {
        $this->secrets[self::key((string) $region->kubeconfig, (string) ($region->project_namespace ?? $region->name), $secretName)] = ['data' => $values, 'labels' => []];
    }

    private function failIfUnreachable(?string $region): void
    {
        if ($this->unreachable) {
            throw SecretBackendException::unreachable($region, new \RuntimeException('connection refused'));
        }
    }

    private static function key(string $kubeconfig, string $namespace, string $name): string
    {
        $server = (string) (Yaml::parse($kubeconfig)['clusters'][0]['cluster']['server'] ?? '');

        return "{$server} {$namespace}/{$name}";
    }
}
