<?php

namespace App\Services\Secrets;

use App\Models\KnativeRegion;
use App\Services\Secrets\OpenBao\OpenBaoClient;

/**
 * Hands out the OpenBao client of a region (or of a cluster by its kubeconfig, for a region move).
 * The container binds it; tests replace it with in-memory OpenBaos (Tests\Concerns\FakeOpenBao).
 */
class OpenBaoClients
{
    public function for(SecretScope $scope): OpenBaoClient
    {
        return $this->forKubeconfig($scope->kubeconfig(), $scope->region?->name);
    }

    public function forRegion(KnativeRegion $region): OpenBaoClient
    {
        return $this->forKubeconfig((string) $region->kubeconfig, $region->name);
    }

    public function forKubeconfig(string $kubeconfig, ?string $region = null): OpenBaoClient
    {
        return new OpenBaoClient($kubeconfig, $region);
    }
}
