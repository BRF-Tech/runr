<?php

namespace App\Services\Ai\Hub;

use App\Models\AiHubCredential;
use App\Models\AiModelService;
use App\Models\AiModelSnapshot;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Ai\Models\ModelServiceManifests;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;

/**
 * The organization's Hugging Face token: stored encrypted (AiHubCredential), handed to the Hub
 * proxy, and written into the region as the Secret `runr-hf-token` (+ tenant suffix) that online
 * model services and mirror Jobs read HF_TOKEN from (secretKeyRef, optional).
 */
class HubToken
{
    /** Per-request memo (the Hub proxy reads it for every call). */
    private ?string $memo = null;

    private bool $loaded = false;

    public function get(): ?string
    {
        if (! $this->loaded) {
            try {
                $this->memo = AiHubCredential::huggingFace()?->token;
            } catch (\Throwable) {
                $this->memo = null;
            }
            $this->loaded = true;
        }

        return $this->memo ?: null;
    }

    public function set(string $token, ?string $userId): AiHubCredential
    {
        $credential = AiHubCredential::updateOrCreate(
            ['provider' => AiHubCredential::HUGGINGFACE],
            ['token' => $token, 'hint' => AiHubCredential::hintFor($token), 'updated_by' => $userId],
        );

        $this->memo = $token;
        $this->loaded = true;

        return $credential;
    }

    public function forget(): void
    {
        AiHubCredential::where('provider', AiHubCredential::HUGGINGFACE)->delete();
        $this->memo = null;
        $this->loaded = true;
    }

    /** Name of the token Secret in the current organization (unique per tenant in a shared namespace). */
    public function secretName(): string
    {
        return Project::slugFor('runr-hf-token');
    }

    /**
     * Write (or remove, without a token) the token Secret in a region namespace. Returns the
     * Secret name when one exists afterwards.
     */
    public function syncSecret(KnativeRegion $region, KubernetesClient $k8s, string $namespace): ?string
    {
        $token = $this->get();
        $name = $this->secretName();

        if (! $token) {
            try {
                $k8s->deleteResource('v1', 'Secret', $name, $namespace);
            } catch (\Throwable) {
            }

            return null;
        }

        $manifest = app(ModelServiceManifests::class)->tokenSecret($name, $namespace, $token);
        if (! $k8s->applyResource($manifest, $namespace)) {
            throw new \RuntimeException(__('ai.hub.secret_failed', ['name' => $name]));
        }

        return $name;
    }

    /**
     * After a token change: update the Secret in every region where the organization runs model
     * services or keeps snapshots (best effort; deploys and mirrors sync it again).
     */
    public function syncAllRegions(): void
    {
        $regionIds = array_unique(array_merge(
            AiModelService::pluck('knative_region_id')->all(),
            AiModelSnapshot::pluck('knative_region_id')->all(),
        ));

        foreach (KnativeRegion::whereIn('id', $regionIds)->get() as $region) {
            if ($region->is_demo || ! $region->kubeconfig) {
                continue;
            }
            try {
                $this->syncSecret($region, new KubernetesClient($region->kubeconfig), $region->project_namespace ?: $region->name);
            } catch (\Throwable $e) {
                Log::warning('HF token Secret sync failed', ['region' => $region->name, 'error' => $e->getMessage()]);
            }
        }
    }
}
