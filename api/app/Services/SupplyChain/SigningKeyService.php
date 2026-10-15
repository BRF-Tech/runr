<?php

namespace App\Services\SupplyChain;

use App\Jobs\GenerateSigningKeyJob;
use App\Models\AuditEvent;
use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cosign key pair of a region (keyless signing is off: no Fulcio, no transparency log).
 *
 * Generation runs in the region: a Job of the tools image calls
 * `cosign generate-key-pair k8s://<supply-chain ns>/runr-cosign-key` with a password RunR
 * generated, so cosign writes the encrypted private key, the public key and the password straight
 * into the key Secret. RunR then keeps an encrypted copy (Laravel encrypted cast) to restore the
 * Secret on a new cluster (region migration) — the private key and its password are never
 * returned by the API and never written to a log.
 *
 * Rotation keeps the previous public key as "retired": admission and the deploy gate keep
 * trusting images signed with it until the retired keys are removed.
 */
class SigningKeyService
{
    public const POLL_SECONDS = 3;

    public const WAIT_SECONDS = 240;

    /** Starts the generation (or rotation) of a region key. */
    public function generate(KnativeRegion $region, bool $rotate = false): RegionSupplyChainSetting
    {
        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);

        if ($settings->signing_status === 'generating') {
            throw new \RuntimeException(__('supply_chain.signing.generating'));
        }

        if ($rotate && $settings->hasSigningKey()) {
            $retired = $settings->signing_retired_keys ?? [];
            $retired[] = [
                'public_key' => $settings->signing_public_key,
                'fingerprint' => $settings->signing_key_fingerprint,
                'created_at' => $settings->signing_key_created_at?->toISOString(),
                'retired_at' => now()->toISOString(),
            ];
            $settings->signing_retired_keys = array_values($retired);
        }

        $settings->forceFill([
            'signing_status' => 'generating',
            'signing_message' => null,
            'signing_job' => 'keygen-'.strtolower(Str::random(8)),
        ])->save();

        GenerateSigningKeyJob::dispatch($region, $rotate, auth()->id());

        return $settings;
    }

    /** Runs the key generation Job in the region and stores the result (GenerateSigningKeyJob). */
    public function runKeygen(KnativeRegion $region, bool $rotate): RegionSupplyChainSetting
    {
        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);
        $k8s = $this->client($region);
        $ns = SupplyChainManifests::namespace();
        $job = $settings->signing_job ?: 'keygen-'.strtolower(Str::random(8));

        $k8s->applyClusterResource(SupplyChainManifests::namespaceManifest());
        foreach (SupplyChainManifests::keygenRbac() as $manifest) {
            $k8s->applyResource($manifest, $ns);
        }

        // A new pair replaces the Secret; the old public key stays trusted as "retired"
        $k8s->deleteResource('v1', 'Secret', SupplyChainManifests::keySecretName(), $ns);
        $k8s->applyResource(SupplyChainManifests::keygenPasswordSecret(Str::random(48)), $ns);
        $k8s->deleteResource('batch/v1', 'Job', $job, $ns, 'Background');

        try {
            if (! $k8s->createResource(SupplyChainManifests::keygenJob($job, $region), $ns)) {
                throw new \RuntimeException("Could not create the key generation Job {$ns}/{$job}");
            }

            $secret = $this->waitForKeySecret($k8s, $job);
            $this->store($settings, $secret, $k8s);
        } finally {
            $this->cleanup($k8s, $job);
        }

        SupplyChainAudit::record($rotate ? 'region.signing_key.rotated' : 'region.signing_key.generated', [
            'fingerprint' => $settings->signing_key_fingerprint,
            'retired' => array_column($settings->signing_retired_keys ?? [], 'fingerprint'),
        ], $region->getKey());

        return $settings;
    }

    public function markFailed(KnativeRegion $region, string $reason): void
    {
        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);
        $settings->forceFill([
            'signing_status' => $settings->signing_public_key ? 'ready' : 'failed',
            'signing_message' => mb_substr($reason, 0, 1000),
        ])->save();

        SupplyChainAudit::record('region.signing_key.failed', ['reason' => mb_substr($reason, 0, 300)], $region->getKey(), AuditEvent::RESULT_FAILURE);
    }

    /** Forgets the retired public keys: images signed only with them are no longer trusted. */
    public function forgetRetired(KnativeRegion $region): RegionSupplyChainSetting
    {
        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);
        // Audited by the route (region.signing_key.retired_remove) with the model change
        $settings->forceFill(['signing_retired_keys' => []])->save();

        return $settings;
    }

    /** SHA-256 of the DER public key (what `cosign public-key` prints, minus the PEM armor). */
    public static function fingerprint(string $publicKeyPem): string
    {
        $body = preg_replace('/-----[^-]+-----|\s+/', '', $publicKeyPem);
        $der = base64_decode((string) $body, true);

        return hash('sha256', $der === false ? $publicKeyPem : $der);
    }

    /** @return array{key: string, pub: string, password: string} */
    private function waitForKeySecret(KubernetesClient $k8s, string $job): array
    {
        $ns = SupplyChainManifests::namespace();
        $deadline = time() + self::WAIT_SECONDS;

        do {
            $secret = $k8s->getResourceStatus('v1', 'Secret', SupplyChainManifests::keySecretName(), $ns);
            $data = $secret['data'] ?? [];
            if (! empty($data['cosign.key']) && ! empty($data['cosign.pub']) && ! empty($data['cosign.password'])) {
                return [
                    'key' => (string) base64_decode($data['cosign.key']),
                    'pub' => (string) base64_decode($data['cosign.pub']),
                    'password' => (string) base64_decode($data['cosign.password']),
                ];
            }

            $status = $k8s->getResourceStatus('batch/v1', 'Job', $job, $ns)['status'] ?? [];
            if (($status['failed'] ?? 0) >= 1) {
                throw new \RuntimeException('Key generation failed: '.$this->jobLog($k8s, $job));
            }

            $this->sleep(self::POLL_SECONDS);
        } while (time() < $deadline);

        throw new \RuntimeException('Key generation did not finish within '.self::WAIT_SECONDS.' s');
    }

    private function store(RegionSupplyChainSetting $settings, array $secret, KubernetesClient $k8s): void
    {
        $fingerprint = self::fingerprint($secret['pub']);

        $settings->forceFill([
            'signing_status' => 'ready',
            'signing_public_key' => trim($secret['pub'])."\n",
            'signing_key_fingerprint' => $fingerprint,
            'signing_private_key' => $secret['key'],
            'signing_key_password' => $secret['password'],
            'signing_key_created_at' => now(),
            'signing_message' => null,
            'signing_job' => null,
        ])->save();

        // ensureKeySecret() compares this with the key the API holds
        $k8s->mergePatch('v1', 'Secret', SupplyChainManifests::keySecretName(), SupplyChainManifests::namespace(), [
            'metadata' => ['annotations' => ['runr.sh/key-fingerprint' => $fingerprint], 'labels' => [SupplyChainManifests::LABEL => 'signing-key']],
        ]);
    }

    private function cleanup(KubernetesClient $k8s, string $job): void
    {
        $ns = SupplyChainManifests::namespace();
        try {
            $k8s->deleteResource('v1', 'Secret', SupplyChainManifests::KEYGEN_PASSWORD, $ns);
            $k8s->deleteResource('batch/v1', 'Job', $job, $ns, 'Background');
            $k8s->deleteResource('rbac.authorization.k8s.io/v1', 'RoleBinding', SupplyChainManifests::KEYGEN_ACCOUNT, $ns);
            $k8s->deleteResource('rbac.authorization.k8s.io/v1', 'Role', SupplyChainManifests::KEYGEN_ACCOUNT, $ns);
            $k8s->deleteResource('v1', 'ServiceAccount', SupplyChainManifests::KEYGEN_ACCOUNT, $ns);
        } catch (\Throwable $e) {
            Log::warning('Key generation cleanup failed', ['error' => $e->getMessage()]);
        }
    }

    private function jobLog(KubernetesClient $k8s, string $job): string
    {
        $ns = SupplyChainManifests::namespace();
        $pods = $k8s->listResources('v1', 'Pod', $ns, ['job-name' => $job]);
        $pod = $pods['items'][0]['metadata']['name'] ?? null;
        $out = PipelineOutput::parse($pod ? $k8s->getPodLogs($pod, $ns, 'supply-chain', 50) : null);

        return $out->step('keygen')['message'] ?? 'no details';
    }

    protected function client(KnativeRegion $region): KubernetesClient
    {
        return new KubernetesClient($region->kubeconfig);
    }

    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
