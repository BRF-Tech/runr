<?php

namespace App\Console\Commands\Concerns;

use App\Services\KubernetesClient;
use App\Services\SupplyChain\ImageRef;

trait E2eTestSetupTrait
{
    private function installComponent(string $regionId, string $component): bool
    {
        $this->info('Installing Knative '.ucfirst($component).' via API...');

        $res = $this->api()->timeout(600)
            ->post("/api/knative-regions/{$regionId}/install-{$component}");

        if (! $res->successful()) {
            $this->error("{$component} install request failed: ".($res->json('message') ?? $res->body()));

            return false;
        }

        return $this->pollComponentStatus($regionId, $component);
    }

    private function pollComponentStatus(string $regionId, string $component): bool
    {
        $deadline = time() + 600;
        $data = [];

        while (time() < $deadline) {
            try {
                $res = $this->api()->get("/api/knative-regions/{$regionId}/component-status");
                $data = $res->json($component) ?? [];
                $status = $data['status'] ?? 'not_installed';

                if ($status === 'ready') {
                    $this->info("  {$component}: ready");

                    return true;
                }
                if ($status === 'error') {
                    $this->error("  {$component}: error — ".($data['message'] ?? 'Unknown'));

                    return false;
                }
            } catch (\Throwable) {
                // keep polling
            }

            sleep(10);
        }

        // Where it stopped: the last status and the tail of the install log
        $this->error("  {$component}: timeout (10m), last status ".($data['status'] ?? 'unknown').': '
            .mb_substr((string) ($data['message'] ?? ''), -1500));

        return false;
    }

    /**
     * Create region with internal Zot registry (PVC, 1Gi) via registry-setup API.
     */
    private function createRegionWithRegistry(string $regionName, string $kubeconfig): ?string
    {
        $this->info("Creating test region with Zot registry: {$regionName}...");

        $storageClass = $this->detectDefaultStorageClass($kubeconfig);
        $this->line("  StorageClass: {$storageClass}");

        $res = $this->api()->timeout(30)->post('/api/registry-setup/start', [
            'name' => $regionName,
            'kubeconfig' => $kubeconfig,
            'nodes' => [],
            'registry_mode' => 'internal',
            'storage_type' => 'pvc',
            'pvc_config' => ['storageClass' => $storageClass, 'size' => '1Gi'],
        ]);

        if (! $res->successful()) {
            $this->error('Registry setup start failed: '.($res->json('message') ?? $res->body()));

            return null;
        }

        $token = $res->json('setup_token');
        if (! $token) {
            $this->error('No setup_token returned');

            return null;
        }

        $this->line('  Setup token: '.$token);

        return $this->pollRegistrySetup($token);
    }

    private function pollRegistrySetup(string $token): ?string
    {
        $deadline = time() + 600;

        while (time() < $deadline) {
            $res = $this->api()->get("/api/registry-setup/{$token}/status");
            $data = $res->json();
            $status = $data['status'] ?? 'unknown';
            $phase = $data['phase'] ?? '';

            if ($status === 'ready') {
                $regionId = $data['region_id'] ?? null;
                $this->line('  <fg=green>✓</> Region + Zot registry created');

                return $regionId;
            }

            if ($status === 'error') {
                $this->error('  Registry setup error: '.($data['message'] ?? 'Unknown'));

                return null;
            }

            $this->line("  [{$status}] {$phase}...");
            sleep(10);
        }

        $this->error('  Registry setup timeout (10m)');

        return null;
    }

    private function installRedis(string $regionId): bool
    {
        $this->info('Installing Redis via API...');

        $res = $this->api()->timeout(600)
            ->post("/api/knative-regions/{$regionId}/redis", [
                'mode' => 'managed',
            ]);

        if (! $res->successful()) {
            $this->error('Redis configure request failed: '.($res->json('message') ?? $res->body()));

            return false;
        }

        // Poll redis status
        $deadline = time() + 300;
        while (time() < $deadline) {
            $res = $this->api()->get("/api/knative-regions/{$regionId}/redis-status");
            $status = $res->json('knative_redis_status') ?? 'none';

            if ($status === 'ready') {
                return true;
            }
            if ($status === 'error') {
                $this->error('Redis install error: '.($res->json('knative_redis_status_message') ?? 'Unknown'));

                return false;
            }
            sleep(5);
        }

        $this->error('Redis install timeout (5m)');

        return false;
    }

    private function selectWorkerNodes(string $regionId): bool
    {
        $this->info('Selecting worker nodes...');

        $res = $this->api()->get("/api/knative-regions/{$regionId}/nodes");
        $nodes = $res->json('nodes') ?? [];
        $workerNames = array_column($nodes, 'name');

        if (empty($workerNames)) {
            $this->warn('No worker nodes found — skipping node selection.');

            return true;
        }

        // Select 1 worker for project pods (others stay for system pods)
        shuffle($workerNames);
        $selected = [array_shift($workerNames)];

        $res = $this->api()->post("/api/knative-regions/{$regionId}/workers", [
            'nodes' => $selected,
        ]);

        if (! $res->successful()) {
            $this->error('Worker selection failed: '.($res->json('message') ?? $res->body()));

            return false;
        }

        $this->info('  Workers selected: '.implode(', ', $selected));

        return true;
    }

    /**
     * In-cluster URL of a project's HTTP function: the host the API gives its users
     * (ProjectResource endpoint_url, {slug}.{knative_domain} — Knative's domain-template
     * {{.Name}}.{{.Domain}}, the region's domain already names the region) over plain HTTP, which
     * the CoreDNS patch sends to Traefik; without a domain the cluster-local service.
     */
    private function e2eFunctionUrl(string $slug, string $path = ''): string
    {
        $domain = $this->getE2eRegion()?->effective_settings['knative_domain'] ?? null;

        return ($domain ? "http://{$slug}.{$domain}" : "http://{$slug}.{$this->getE2eNamespace()}.svc.cluster.local").$path;
    }

    /**
     * Patch CoreDNS to resolve *.{knative_domain} → Traefik ClusterIP.
     * Enables e2e tests to reach Knative services via external domain
     * without real DNS — CoreDNS template returns Traefik's ClusterIP
     * for any subdomain under the configured Knative domain.
     */
    private function patchCoreDnsForTesting(): void
    {
        $region = $this->getE2eRegion();
        $k8s = $this->getE2eKubernetesClient();
        $domain = $region->effective_settings['knative_domain'] ?? null;

        if (! $domain) {
            $this->warn('No knative_domain — skipping CoreDNS patch');

            return;
        }

        $traefikNs = $region->knative_serving_namespace ?? 'knative-serving';
        $svc = $k8s->getResourceStatus('v1', 'Service', 'traefik', $traefikNs);
        $clusterIp = $svc['spec']['clusterIP'] ?? null;

        if (! $clusterIp) {
            $this->warn('Traefik ClusterIP not found — skipping CoreDNS patch');

            return;
        }

        $this->info("Patching CoreDNS: *.{$domain} → {$clusterIp}");

        $cm = $k8s->getResourceStatus('v1', 'ConfigMap', 'coredns', 'kube-system');
        $corefile = $cm['data']['Corefile'] ?? '';

        if (str_contains($corefile, $domain)) {
            $this->line('  CoreDNS already patched for this domain');

            return;
        }

        $template = "    template IN A {$domain} {\n"
                  ."        answer \"{{ .Name }} 60 IN A {$clusterIp}\"\n"
                  ."        fallthrough\n"
                  ."    }\n";

        $corefile = str_replace(
            '    forward .',
            $template.'    forward .',
            $corefile,
        );

        $k8s->applyResource([
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => ['name' => 'coredns'],
            'data' => ['Corefile' => $corefile],
        ], 'kube-system');

        // Restart CoreDNS pods to pick up new config
        $pods = $k8s->listResources('v1', 'Pod', 'kube-system', ['k8s-app' => 'kube-dns']);
        foreach ($pods['items'] ?? [] as $pod) {
            $podName = $pod['metadata']['name'] ?? '';
            if ($podName) {
                $k8s->deleteResource('v1', 'Pod', $podName, 'kube-system');
            }
        }

        sleep(5); // CoreDNS pods need a moment to restart
        $this->line('  <fg=green>✓</> CoreDNS patched');
    }

    /**
     * Knative resolves every image tag of a Revision to a digest on its registry, init containers
     * included. The e2e harness builds RunR's own images from this checkout (the universal wrapper
     * of runtime plugins, the hf-serve image, …) and loads them into the Kind nodes under their
     * release names (ghcr.io/runrsh/runr-wrapper:<version>); a tag that is not on the registry yet
     * fails the Revision ("failed to resolve image to digest"). The e2e region adds that registry
     * to Knative's registries-skipping-tag-resolving, next to Knative's own defaults, so the nodes
     * run the loaded images by their tags. Regions outside the e2e keep the resolution.
     */
    private function skipTagResolutionForLoadedImages(): void
    {
        $region = $this->getE2eRegion();
        $k8s = $this->getE2eKubernetesClient();
        $ns = $region->knative_serving_namespace ?? 'knative-serving';
        $registries = self::e2eRegistriesSkippingTagResolving((string) config('executor.wrapper_image'));

        $this->info("Knative tag resolution: skipped for {$registries} (images loaded into Kind)");
        $patched = $k8s->mergePatch('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $ns, [
            'spec' => ['config' => ['deployment' => ['registries-skipping-tag-resolving' => $registries]]],
        ]);
        if (! $patched) {
            $this->warn('  KnativeServing patch rejected');

            return;
        }

        // The operator copies spec.config into the config-deployment ConfigMap the controller reads
        $deadline = time() + 120;
        while (time() < $deadline) {
            $cm = $k8s->getResourceStatus('v1', 'ConfigMap', 'config-deployment', $ns);
            if (($cm['data']['registries-skipping-tag-resolving'] ?? null) === $registries) {
                $this->line('  <fg=green>✓</> config-deployment updated');

                return;
            }
            sleep(3);
        }
        $this->warn('  config-deployment not updated after 120s');
    }

    /**
     * Knative's defaults plus the registry RunR's own images are loaded into Kind under (the
     * registry of the configured wrapper image).
     */
    public static function e2eRegistriesSkippingTagResolving(string $wrapperImage): string
    {
        $registries = ['kind.local', 'ko.local', 'dev.local'];
        $registry = ImageRef::parse($wrapperImage)->registry;
        // Knative compares the registry as go-containerregistry names it
        $registry = $registry === 'docker.io' ? 'index.docker.io' : $registry;
        if (! in_array($registry, $registries, true)) {
            $registries[] = $registry;
        }

        return implode(',', $registries);
    }

    /**
     * Detect the default StorageClass from the cluster.
     * Falls back to 'standard' if none is annotated as default.
     */
    private function detectDefaultStorageClass(string $kubeconfig): string
    {
        $k8s = new KubernetesClient($kubeconfig);
        $list = $k8s->listClusterResources('storage.k8s.io/v1', 'StorageClass');

        foreach ($list['items'] ?? [] as $sc) {
            $annotations = $sc['metadata']['annotations'] ?? [];
            $isDefault = $annotations['storageclass.kubernetes.io/is-default-class'] ?? 'false';
            if ($isDefault === 'true') {
                return $sc['metadata']['name'];
            }
        }

        return 'standard';
    }
}
