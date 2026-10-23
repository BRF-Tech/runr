<?php

namespace Tests\Concerns;

use App\Models\KnativeRegion;
use App\Services\Secrets\RegionSecrets;

/**
 * Project secret values live in the region (pass-through, docs/security.md): tests keep them in
 * an in-memory RegionSecrets (FakeRegionSecrets) instead of a cluster. Secrets are keyed by the
 * cluster's API server, the namespace and the Secret's name, so a region move between two
 * kubeconfigs is two clusters.
 *
 *   $this->fakeRegionSecrets();
 *   $region = $this->regionWithCluster();
 *   $this->regionSecrets->values($region, 'orders-secret-env')   // what the "cluster" holds
 *   $this->regionSecrets->unreachable = true                    // every call fails like a dead cluster
 */
trait FakesRegionSecrets
{
    protected FakeRegionSecrets $regionSecrets;

    protected function fakeRegionSecrets(): FakeRegionSecrets
    {
        $this->regionSecrets = new FakeRegionSecrets;
        $this->app->instance(RegionSecrets::class, $this->regionSecrets);

        return $this->regionSecrets;
    }

    protected static function kubeconfigFor(string $server): string
    {
        return "apiVersion: v1\nkind: Config\ncurrent-context: c\nclusters:\n- name: k\n  cluster:\n    server: {$server}\n    insecure-skip-tls-verify: true\ncontexts:\n- name: c\n  context: {cluster: k, user: u}\nusers:\n- name: u\n  user: {token: abc}\n";
    }

    protected function regionWithCluster(string $name = 'op', string $server = 'https://k8s.test'): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => $name,
            'project_namespace' => $name,
            'kubeconfig' => self::kubeconfigFor($server),
            'is_active' => true,
        ]);
    }
}
