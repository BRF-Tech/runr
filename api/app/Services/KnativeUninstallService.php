<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Services\Concerns\KnativeClusterTrait;
use App\Services\Concerns\KnativeCrdTrait;
use App\Services\Concerns\KnativeHelmTrait;
use App\Services\Concerns\KnativeLogTrait;
use App\Services\Concerns\KnativeTraefikTrait;
use App\Services\Contracts\KnativeConstants;
use App\Services\Observability\LoadTest\LoadTestRunner;
use App\Services\Observability\LoadTest\LoadTestService;
use Illuminate\Support\Facades\Log;
use Throwable;

class KnativeUninstallService implements KnativeConstants
{
    use KnativeClusterTrait, KnativeCrdTrait, KnativeHelmTrait, KnativeLogTrait, KnativeTraefikTrait;

    /**
     * Uninstall Knative from the cluster (legacy full uninstall). The operator and Serving
     * component statuses follow — the region's knative_status is computed from them, so a
     * region whose components stayed "ready" kept showing Knative as installed.
     */
    public function uninstall(KnativeRegion $region, bool $removeTraefik = false): void
    {
        $region->update([
            'knative_status' => 'uninstalling',
            'knative_status_message' => '',
            'knative_operator_status' => 'uninstalling',
            'knative_serving_status' => 'uninstalling',
        ]);

        try {
            $this->appendLog($region, 'Starting Knative uninstall...');
            $this->cleanup($region, $removeTraefik);
            $this->removeLoadTests($region, fn (string $line) => $this->appendLog($region, $line));
            $this->appendLog($region, 'Knative uninstalled successfully.');

            $region->update([
                'knative_status' => 'not_installed',
                'knative_status_message' => $region->knative_status_message,
                'knative_status_checked_at' => now(),
                'knative_operator_status' => 'not_installed',
                'knative_operator_status_message' => null,
                'knative_serving_status' => 'not_installed',
                'knative_serving_status_message' => null,
                'knative_serving_version' => null,
            ]);

            Log::info('Knative uninstalled', ['region' => $region->name]);

        } catch (Throwable $e) {
            $this->appendLog($region, 'ERROR: '.$e->getMessage());

            $region->update([
                'knative_status' => 'error',
                'knative_status_message' => $region->knative_status_message,
                'knative_status_checked_at' => now(),
                'knative_operator_status' => 'error',
                'knative_serving_status' => 'error',
            ]);

            throw $e;
        }
    }

    /**
     * Uninstall Knative Operator from the cluster.
     */
    public function uninstallOperator(KnativeRegion $region): void
    {
        $region->update([
            'knative_operator_status' => 'uninstalling',
            'knative_operator_status_message' => '',
        ]);

        try {
            $this->appendComponentLog($region, 'operator', 'Starting Knative Operator uninstall...');
            $this->cleanupOperator($region);
            $this->appendComponentLog($region, 'operator', 'Knative Operator uninstalled.');

            $region->update([
                'knative_operator_status' => 'not_installed',
                'knative_status_checked_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->appendComponentLog($region, 'operator', 'ERROR: '.$e->getMessage());
            $region->update([
                'knative_operator_status' => 'error',
                'knative_status_checked_at' => now(),
            ]);
            throw $e;
        }
    }

    /**
     * Uninstall Knative Serving from the cluster.
     * Traefik stays while it serves non-Knative Ingresses (e.g. the RunR console on a
     * single-cluster install) unless $removeTraefik is set.
     */
    public function uninstallServing(KnativeRegion $region, bool $removeTraefik = false): void
    {
        $region->update([
            'knative_serving_status' => 'uninstalling',
            'knative_serving_status_message' => '',
        ]);

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $ns = $region->knative_serving_namespace ?? 'knative-serving';

            // 1. Delete KnativeServing CR
            $this->appendComponentLog($region, 'serving', 'Deleting KnativeServing CR...');
            try {
                $k8s->deleteResource(
                    'operator.knative.dev/v1beta1', 'KnativeServing',
                    'knative-serving', $ns
                );
                $this->waitFor(fn () => $k8s->getResourceStatus(
                    'operator.knative.dev/v1beta1', 'KnativeServing',
                    'knative-serving', $ns
                ) === null, 60);
            } catch (Throwable $e) {
                Log::debug('Cleanup: KnativeServing CR removal skipped', ['error' => $e->getMessage()]);
            }

            // 1b. The project namespace's NetworkPolicy (the activator's goes with the namespace)
            RegionNetworkPolicyService::remove($k8s, $region->project_namespace ?: $region->name);

            // 1c. Load tests target the region's ingress, which goes now: their namespace too
            $this->removeLoadTests($region, fn (string $line) => $this->appendComponentLog($region, 'serving', $line));

            // 2. Uninstall Traefik + RBAC (kept while it serves non-Knative Ingresses)
            $this->appendComponentLog($region, 'serving', 'Uninstalling Traefik...');
            $traefikKeptFor = $this->uninstallTraefikIfUnused($k8s, new HelmService, $ns, $region->kubeconfig, $removeTraefik);

            // 3. Delete namespace if managed by runr (it still hosts a kept Traefik)
            if ($traefikKeptFor) {
                $this->appendComponentLog($region, 'serving', $this->traefikKeptMessage($traefikKeptFor));
            } else {
                $this->appendComponentLog($region, 'serving', 'Cleaning up namespace...');
                $this->deleteNamespaceIfManaged($k8s, $ns);
            }

            $this->appendComponentLog($region, 'serving', 'Knative Serving uninstalled.');

            $region->update([
                'knative_serving_status' => 'not_installed',
                'knative_serving_version' => null,
                'knative_status_checked_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->appendComponentLog($region, 'serving', 'ERROR: '.$e->getMessage());
            $region->update([
                'knative_serving_status' => 'error',
                'knative_status_checked_at' => now(),
            ]);
            throw $e;
        }
    }

    /**
     * The load-test namespace of the cluster (docs/load-test.md) — its policies, its quota and the
     * k6 Jobs still running — and the tests of the region in the current organization, which end
     * as failed with the reason. Tests of other organizations end when their next check finds no
     * Job. Best effort: the uninstall goes on without it.
     *
     * @param  callable(string): void  $log
     */
    private function removeLoadTests(KnativeRegion $region, callable $log): void
    {
        try {
            $ended = app(LoadTestService::class)->endForRegion((string) $region->id, __('observability.load_test.region_uninstalled'));
            if (app(LoadTestRunner::class)->removeNamespace(new KubernetesClient($region->kubeconfig))) {
                $log('Removed the load-test namespace'.($ended ? " ({$ended} running test(s) stopped)" : '').'.');
            }
        } catch (Throwable $e) {
            Log::warning('Cleanup: load-test namespace not removed', ['region' => $region->name, 'error' => $e->getMessage()]);
        }
    }
}
