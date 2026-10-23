<?php

namespace App\Services\Secrets\OpenBao;

use App\Models\BindingAttachment;
use App\Models\KnativeRegion;
use App\Models\ProjectSecret;
use App\Models\RegionSecretSetting;
use App\Services\Bindings\DynamicDbCredentials;
use App\Services\KubernetesClient;
use App\Services\Secrets\OpenBaoClients;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Installs and operates the region's OpenBao (docs/secret-stores.md, "OpenBao in the region"),
 * the third place a project secret can live (OpenBaoSecretBackend) and the issuer of dynamic
 * Postgres credentials (DynamicDbCredentials):
 *
 *  1. namespace runr-openbao, the TLS key pair (issued once, kept in the region's Secret), the
 *     manifests (OpenBaoManifests), the TokenReview binding of its service account;
 *  2. waits until the server answers;
 *  3. not initialized: initializes it with one key share — the unseal key goes into the region's
 *     Secret runr-openbao-unseal for the unsealer, never into RunR's database — unseals it,
 *     configures it with the root token (OpenBaoAccess::configure()) and revokes the root token;
 *     initialized (a reinstall, a second region on the cluster): unsealed by the unsealer, checked
 *     with RunR's own login.
 *
 * Needs External Secrets Operator in the region: the workloads read OpenBao through it.
 */
class OpenBaoInstallService
{
    /** Seconds the install waits for the server to answer. */
    public const WAIT_SECONDS = 300;

    /**
     * @throws Throwable the install failed (the status says why)
     */
    public function install(KnativeRegion $region): RegionSecretSetting
    {
        $settings = RegionSecretSetting::forRegion($region);
        if (! $region->kubeconfig || $region->is_demo) {
            throw new RuntimeException(__('api.region.kubeconfig_missing'));
        }
        if (! $settings->esoReady()) {
            throw new RuntimeException(__('api.secret.openbao_needs_eso', ['region' => $region->name]));
        }

        $settings->fill(['openbao_status' => RegionSecretSetting::ESO_INSTALLING, 'openbao_message' => null])->save();

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $image = (string) config('secrets.openbao.image');

            $k8s->applyClusterResource(OpenBaoManifests::namespace());
            $ca = $this->ensureTls($k8s, $settings);
            if (! $k8s->applyClusterResource(OpenBaoManifests::tokenReviewBinding())) {
                throw new RuntimeException('applying the TokenReview binding of OpenBao failed');
            }
            foreach (OpenBaoManifests::namespaced($image, (string) config('secrets.openbao.storage_size'), (string) config('secrets.openbao.storage_class')) as $manifest) {
                if (! $k8s->applyResource($manifest, OpenBaoClient::NAMESPACE)) {
                    throw new RuntimeException("applying {$manifest['kind']} {$manifest['metadata']['name']} failed");
                }
            }

            $client = $this->client($region);
            $health = $this->waitForServer($client);
            $this->bootstrap($k8s, $client, $health);

            $settings->fill([
                'openbao_status' => RegionSecretSetting::ESO_READY,
                'openbao_message' => null,
                'openbao_version' => OpenBaoManifests::version($image),
                'openbao_installed_at' => now(),
                'openbao_ca' => $ca,
            ])->save();
        } catch (Throwable $e) {
            $settings->fill(['openbao_status' => RegionSecretSetting::ESO_ERROR, 'openbao_message' => mb_substr($e->getMessage(), 0, 2000)])->save();
            Log::warning('OpenBao install failed', ['region' => $region->name, 'error' => $e->getMessage()]);

            throw $e;
        }

        return $settings;
    }

    /**
     * Remove OpenBao and its volume. Refused while projects keep values in it or bindings get
     * credentials from it — on this region or another one on the same cluster.
     *
     * @throws RuntimeException
     */
    public function uninstall(KnativeRegion $region): void
    {
        if ($this->valuesOn($region) > 0) {
            throw new RuntimeException(__('api.secret.openbao_in_use', ['region' => $region->name]));
        }
        // One OpenBao per cluster: another region on it keeps it
        if (($sibling = $this->siblingWithOpenBao($region)) !== null) {
            RegionSecretSetting::forRegion($region)->fill([
                'openbao_status' => RegionSecretSetting::ESO_NOT_INSTALLED,
                'openbao_message' => "OpenBao stays on the cluster: region {$sibling} uses it",
                'openbao_installed_at' => null,
            ])->save();

            return;
        }

        $k8s = new KubernetesClient((string) $region->kubeconfig);
        $k8s->deleteClusterResource('rbac.authorization.k8s.io/v1', 'ClusterRoleBinding', OpenBaoManifests::TOKEN_REVIEW_BINDING);
        if (! $k8s->deleteClusterResource('v1', 'Namespace', OpenBaoClient::NAMESPACE)) {
            throw new RuntimeException('deleting namespace '.OpenBaoClient::NAMESPACE.' failed');
        }

        RegionSecretSetting::forRegion($region)->fill([
            'openbao_status' => RegionSecretSetting::ESO_NOT_INSTALLED,
            'openbao_message' => null,
            'openbao_version' => null,
            'openbao_installed_at' => null,
            'openbao_ca' => null,
        ])->save();
    }

    /** Another region on the same cluster whose OpenBao is installed (the same OpenBao): its name. */
    private function siblingWithOpenBao(KnativeRegion $region): ?string
    {
        return RegionSecretSetting::siblingWith($region, fn (RegionSecretSetting $s) => $s->openbao_status !== RegionSecretSetting::ESO_NOT_INSTALLED);
    }

    /**
     * What of the region's projects depends on its OpenBao (current context): secret variables
     * whose value lives in it, and binding attachments it issues dynamic credentials for.
     */
    public function valuesOn(KnativeRegion $region): int
    {
        $values = ProjectSecret::query()
            ->where('source', ProjectSecret::SOURCE_OPENBAO)
            ->whereHas('project', fn ($q) => $q->where('knative_region_id', $region->getKey()))
            ->count();

        // Binding attachments with dynamic credentials issued by it count too
        $dynamic = DynamicDbCredentials::supported()
            ? BindingAttachment::query()
                ->where('credential_mode', DynamicDbCredentials::MODE_DYNAMIC)
                ->whereHas('project', fn ($q) => $q->where('knative_region_id', $region->getKey()))
                ->count()
            : 0;

        return $values + $dynamic;
    }

    /**
     * The TLS key pair in the region's Secret: kept while its certificate has more than 60 days,
     * issued anew otherwise. Returns the CA certificate (public).
     */
    private function ensureTls(KubernetesClient $k8s, RegionSecretSetting $settings): string
    {
        $secret = $k8s->getResourceOrFail('v1', 'Secret', OpenBaoManifests::TLS_SECRET, OpenBaoClient::NAMESPACE);
        $cert = base64_decode((string) ($secret['data']['tls.crt'] ?? ''), true) ?: '';
        $ca = base64_decode((string) ($secret['data']['ca.crt'] ?? ''), true) ?: '';
        if ($cert !== '' && $ca !== '' && (OpenBaoTls::daysLeft($cert) ?? 0) > 60) {
            return $ca;
        }

        $tls = OpenBaoTls::issue();
        if (! $k8s->applyResource(OpenBaoManifests::tlsSecret($tls), OpenBaoClient::NAMESPACE)) {
            throw new RuntimeException('writing the TLS Secret of OpenBao failed');
        }
        unset($tls['key']);

        return $tls['ca'];
    }

    /** @return array{initialized: bool, sealed: bool, version: string|null} */
    private function waitForServer(OpenBaoClient $client): array
    {
        $deadline = time() + self::WAIT_SECONDS;
        while (time() < $deadline) {
            $health = $client->health();
            if ($health !== null) {
                return $health;
            }
            Sleep::sleep(5);
        }

        throw new RuntimeException('OpenBao did not answer within '.self::WAIT_SECONDS.' seconds; see the pod openbao-0 in namespace '.OpenBaoClient::NAMESPACE);
    }

    /** @param array{initialized: bool, sealed: bool, version: string|null} $health */
    private function bootstrap(KubernetesClient $k8s, OpenBaoClient $client, array $health): void
    {
        if (! $health['initialized']) {
            $init = $client->init();
            try {
                if (! $k8s->applyResource(OpenBaoManifests::unsealSecret($init['key']), OpenBaoClient::NAMESPACE)) {
                    throw new RuntimeException('writing the unseal key into the region failed; OpenBao stays sealed after a restart until it is written');
                }
                $client->unseal($init['key']);
                $root = $client->withToken($init['root']);
                OpenBaoAccess::configure($root);
                $root->write('auth/token/revoke-self');
            } finally {
                unset($init, $root);
            }

            return;
        }

        if ($health['sealed']) {
            $secret = $k8s->getResourceOrFail('v1', 'Secret', OpenBaoManifests::UNSEAL_SECRET, OpenBaoClient::NAMESPACE);
            $key = base64_decode((string) ($secret['data']['key'] ?? ''), true);
            if (! is_string($key) || $key === '') {
                throw new RuntimeException('OpenBao is sealed and the region has no unseal key ('.OpenBaoManifests::UNSEAL_SECRET.')');
            }
            $client->unseal($key);
            unset($key);
        }

        // Initialized earlier: RunR's own login must work
        $client->login();
    }

    private function client(KnativeRegion $region): OpenBaoClient
    {
        return app(OpenBaoClients::class)->forRegion($region);
    }
}
