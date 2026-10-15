<?php

namespace App\Jobs;

use App\Events\PlatformEvent;
use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use App\Services\SandboxInstallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The /dev/kvm check of a region's Kata target nodes (KataKvmProbeTrait), queued by the console's
 * kata-shims call when nodes have no result yet (or on ?refresh=1). One at a time per region;
 * broadcasts sandbox.kvm.probed so the console reloads the hypervisor list.
 */
class ProbeKvmJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public KnativeRegion $region, public bool $all = false) {}

    public function uniqueId(): string
    {
        return $this->region->id;
    }

    public function handle(SandboxInstallService $service): void
    {
        $summary = $service->ensureKvmProbed(new KubernetesClient($this->region->kubeconfig), $this->all);

        try {
            event(new PlatformEvent('sandbox.kvm.probed', [
                'region_id' => $this->region->id,
                'kvm' => $summary['state'],
            ]));
        } catch (\Throwable $e) {
            Log::warning('KVM check result not broadcast', ['region' => $this->region->name, 'error' => $e->getMessage()]);
        }
    }
}
