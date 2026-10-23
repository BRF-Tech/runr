<?php

namespace App\Services\RegionReconcile\Steps;

use App\Models\RegionSecretSetting;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\Secrets\OpenBao\OpenBaoClient;
use App\Services\Secrets\OpenBao\OpenBaoInstallService;
use App\Services\Secrets\OpenBao\OpenBaoManifests;
use App\Services\Secrets\OpenBao\OpenBaoTls;

/**
 * The region's own OpenBao (docs/secret-stores.md), on clusters where a region has it: the image
 * this release pins (config secrets.openbao.image) and a TLS certificate with more than 60 days
 * left. Otherwise the install runs again — the manifests applied anew (the pod restarts and the
 * unsealer unseals it with the region's key; the data stays on its volume), a new certificate
 * issued when the old one runs out. A region without OpenBao is left alone.
 */
final class OpenBaoStep implements ReconcileStep
{
    public function name(): string
    {
        return 'openbao';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $target = null;
        foreach ($cluster->targets as $candidate) {
            if (RegionSecretSetting::forRegion($candidate->region)->openbaoReady()) {
                $target = $candidate;
                break;
            }
        }
        if (! $target) {
            return [];
        }

        $k8s = $cluster->k8s;
        $image = (string) config('secrets.openbao.image');
        $set = $k8s->getResourceStatus('apps/v1', 'StatefulSet', 'openbao', OpenBaoClient::NAMESPACE);
        $running = $set['spec']['template']['spec']['containers'][0]['image'] ?? null;
        $tls = $k8s->getResourceStatus('v1', 'Secret', OpenBaoManifests::TLS_SECRET, OpenBaoClient::NAMESPACE);
        $days = OpenBaoTls::daysLeft((string) base64_decode((string) ($tls['data']['tls.crt'] ?? ''), true));

        $why = [];
        if ($running !== $image) {
            $why[] = 'image '.($running ?? 'missing').' → '.$image;
        }
        if ($days === null || $days <= 60) {
            $why[] = 'TLS certificate '.($days === null ? 'missing' : "ends in {$days} days").' → a new one';
        }
        if ($why === []) {
            return [];
        }

        $what = 'OpenBao of the region ('.implode('; ', $why).'); the pod restarts and is unsealed again, its data stays';
        if ($dryRun) {
            return ["would install {$what}"];
        }

        $target->run(fn () => app(OpenBaoInstallService::class)->install($target->region));

        return ["installed {$what}"];
    }
}
