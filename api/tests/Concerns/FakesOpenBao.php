<?php

namespace Tests\Concerns;

use App\Models\KnativeRegion;
use App\Models\RegionSecretSetting;
use App\Services\Secrets\OpenBao\OpenBaoClient;
use App\Services\Secrets\OpenBaoClients;
use Symfony\Component\Yaml\Yaml;

/**
 * The region's OpenBao in memory (FakeOpenBao), one per cluster API server — a region move between
 * two kubeconfigs is two OpenBaos.
 *
 *   $this->fakeOpenBao();
 *   $this->openBaoReady($region);            // External Secrets Operator + OpenBao ready
 *   $this->openBao($region)->kv['projects/…'] // what the region's OpenBao holds
 */
trait FakesOpenBao
{
    protected function fakeOpenBao(): void
    {
        $this->app->instance(OpenBaoClients::class, new class extends OpenBaoClients
        {
            /** @var array<string, FakeOpenBao> API server => its OpenBao */
            public array $instances = [];

            public function forKubeconfig(string $kubeconfig, ?string $region = null): OpenBaoClient
            {
                $server = (string) (Yaml::parse($kubeconfig)['clusters'][0]['cluster']['server'] ?? '');

                return $this->instances[$server] ??= new FakeOpenBao;
            }
        });
    }

    protected function openBao(KnativeRegion $region): FakeOpenBao
    {
        $client = app(OpenBaoClients::class)->forRegion($region);
        assert($client instanceof FakeOpenBao);

        return $client;
    }

    protected function openBaoReady(KnativeRegion $region, bool $default = true): RegionSecretSetting
    {
        $settings = RegionSecretSetting::forRegion($region);
        $settings->fill([
            'eso_status' => RegionSecretSetting::ESO_READY,
            'openbao_status' => RegionSecretSetting::ESO_READY,
            'openbao_ca' => "-----BEGIN CERTIFICATE-----\nMIIBfake\n-----END CERTIFICATE-----\n",
            'openbao_default' => $default,
        ])->save();

        return $settings;
    }
}
