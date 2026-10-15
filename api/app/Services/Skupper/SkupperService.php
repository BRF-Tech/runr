<?php

namespace App\Services\Skupper;

use App\Models\KnativeRegion;
use App\Models\PrivateDatabaseBinding;
use App\Models\Project;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;

/**
 * Private access from functions to Managed PostgreSQL through Skupper v2.
 *
 * Region level: the Skupper controller + CRDs (skupper.io/v2alpha1) are part of the cluster's
 * platform stack — RunR detects them via API discovery and never installs Skupper itself.
 * RunR ensures one Skupper Site in the region's project namespace (an existing Site there is
 * reused). Project level: each PrivateDatabaseBinding adds a Link/AccessToken, a Listener and a
 * credentials Secret to that namespace and env vars to the project's Knative Service / CronJob.
 * Everything goes through the region's own kubeconfig.
 */
class SkupperService
{
    /** @var array<string, KubernetesClient> */
    private array $clients = [];

    public static function namespaceFor(KnativeRegion $region): string
    {
        return $region->project_namespace ?? $region->name;
    }

    private function client(KnativeRegion $region): KubernetesClient
    {
        if (! $region->kubeconfig) {
            throw new SkupperException("Region {$region->name} has no kubeconfig.");
        }

        return $this->clients[$region->id] ??= new KubernetesClient($region->kubeconfig);
    }

    // ── Region: detection + site ─────────────────────────────────────────────

    /**
     * Is Skupper v2 served by the region's API server (CRDs installed, controller expected)?
     */
    public function available(KnativeRegion $region): bool
    {
        $list = $this->client($region)->getApiResources(SkupperManifests::API_VERSION);
        $resources = array_column($list['resources'] ?? [], 'name');

        return ! array_diff(['sites', 'links', 'listeners', 'accesstokens'], $resources);
    }

    /**
     * Region capability: {available, api_version, namespace, site: {name, status, message}|null}.
     */
    public function regionStatus(KnativeRegion $region): array
    {
        $available = $this->available($region);
        $namespace = self::namespaceFor($region);

        return [
            'available' => $available,
            'api_version' => SkupperManifests::API_VERSION,
            'namespace' => $namespace,
            'site' => $available ? $this->site($region) : null,
        ];
    }

    /**
     * The Site of the project namespace (Skupper allows one per namespace), or null.
     */
    public function site(KnativeRegion $region): ?array
    {
        $list = $this->client($region)->listResources(SkupperManifests::API_VERSION, 'Site', self::namespaceFor($region));
        $site = $list['items'][0] ?? null;

        return $site ? ['name' => $site['metadata']['name'] ?? null] + self::statusOf($site) : null;
    }

    /**
     * Create the namespace's Site unless one exists already.
     */
    public function ensureSite(KnativeRegion $region): array
    {
        if (! $this->available($region)) {
            throw new SkupperException(__('api.private_connection.skupper_unavailable', ['region' => $region->name]));
        }

        $existing = $this->site($region);
        if ($existing) {
            return $existing;
        }

        $namespace = self::namespaceFor($region);
        if (! $this->client($region)->applyResource(SkupperManifests::site($namespace), $namespace)) {
            throw new SkupperException("Could not create the Skupper Site in namespace {$namespace}.");
        }

        Log::info('Skupper site created', ['region' => $region->name, 'namespace' => $namespace]);

        return ['name' => SkupperManifests::SITE_NAME, 'status' => 'Pending', 'message' => null];
    }

    // ── Binding lifecycle ────────────────────────────────────────────────────

    /**
     * A Listener of another tenant with the same routing key would share its connectors
     * (Skupper routes by key inside the linked network): refuse it.
     */
    public function routingKeyInUse(KnativeRegion $region, Project $project, string $routingKey): bool
    {
        $namespace = self::namespaceFor($region);
        $list = $this->client($region)->listResources(SkupperManifests::API_VERSION, 'Listener', $namespace, ['app.kubernetes.io/managed-by' => 'runr']);
        $tenant = SkupperManifests::labels($project)['runr/tenant'] ?? null;

        foreach ($list['items'] ?? [] as $listener) {
            if (($listener['spec']['routingKey'] ?? null) !== $routingKey) {
                continue;
            }
            if (($listener['metadata']['labels']['runr/tenant'] ?? null) !== $tenant) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply the Kubernetes side of a binding: Site (ensured), credentials Secret, link, Listener.
     * The env of the running workloads follows with syncProjectEnv().
     *
     * @param  array  $link  SkupperLinkParser::parse() result
     * @param  array  $credentials  {database, username, password}
     */
    public function apply(PrivateDatabaseBinding $binding, Project $project, array $link, array $credentials): void
    {
        $region = $project->knativeRegion;
        $k8s = $this->client($region);
        $namespace = self::namespaceFor($region);

        $this->ensureSite($region);

        $manifests = array_merge(
            [SkupperManifests::credentialsSecret($binding, $project, $namespace, $credentials)],
            SkupperManifests::link($link, $binding, $project, $namespace),
            [SkupperManifests::listener($binding, $project, $namespace)],
        );

        foreach ($manifests as $manifest) {
            if (! $k8s->applyResource($manifest, $namespace)) {
                throw new SkupperException("Could not apply {$manifest['kind']} {$manifest['metadata']['name']} in namespace {$namespace}.");
            }
        }

        Log::info('Private database connection applied', ['project' => $project->slug, 'binding' => $binding->resource_name]);
    }

    /**
     * Delete a binding's Kubernetes resources (404 = already gone).
     *
     * @return bool true when every delete succeeded
     */
    public function remove(PrivateDatabaseBinding $binding, KnativeRegion $region): bool
    {
        $k8s = $this->client($region);
        $namespace = self::namespaceFor($region);
        $name = $binding->resource_name;

        $targets = [
            [SkupperManifests::API_VERSION, 'Listener', $name],
            [SkupperManifests::API_VERSION, 'AccessToken', $name],
            [SkupperManifests::API_VERSION, 'Link', $name],
            ['v1', 'Secret', SkupperManifests::linkSecretName($binding)],
            ['v1', 'Secret', $name],               // TLS Secret the controller creates when redeeming an AccessToken
            ['v1', 'Secret', $binding->secret_name],
        ];

        $ok = true;
        foreach ($targets as [$apiVersion, $kind, $resource]) {
            $ok = $k8s->deleteResource($apiVersion, $kind, $resource, $namespace) && $ok;
        }

        return $ok;
    }

    /**
     * Live status of a binding: link (AccessToken redemption + Link), listener, overall state.
     */
    public function status(PrivateDatabaseBinding $binding, KnativeRegion $region): array
    {
        $k8s = $this->client($region);
        $namespace = self::namespaceFor($region);
        $name = $binding->resource_name;

        $link = $k8s->getResourceStatus(SkupperManifests::API_VERSION, 'Link', $name, $namespace);
        $listener = $k8s->getResourceStatus(SkupperManifests::API_VERSION, 'Listener', $name, $namespace);
        $token = $binding->link_kind === 'access_token'
            ? $k8s->getResourceStatus(SkupperManifests::API_VERSION, 'AccessToken', $name, $namespace)
            : null;

        $linkStatus = $link ? self::statusOf($link) : ['status' => $token ? 'Pending' : 'Missing', 'message' => null];
        $listenerStatus = $listener
            ? self::statusOf($listener) + ['has_matching_connector' => (bool) ($listener['status']['hasMatchingConnector'] ?? false)]
            : ['status' => 'Missing', 'message' => null, 'has_matching_connector' => false];

        $out = ['link' => $linkStatus, 'listener' => $listenerStatus];
        if ($binding->link_kind === 'access_token') {
            $out['access_token'] = $token
                ? self::statusOf($token) + ['redeemed' => (bool) ($token['status']['redeemed'] ?? false)]
                : ['status' => 'Missing', 'message' => null, 'redeemed' => false];
        }

        $statuses = array_merge([$linkStatus['status'], $listenerStatus['status']], isset($out['access_token']) ? [$out['access_token']['status']] : []);
        $out['state'] = match (true) {
            in_array('Error', $statuses, true) => 'error',
            $linkStatus['status'] === 'Ready' && $listenerStatus['has_matching_connector'] => 'ready',
            default => 'pending',
        };

        return $out;
    }

    /**
     * Put the project's current bindings into the env of its running Knative Service and
     * CronJob (a new revision picks them up); removed bindings disappear from the env.
     * Not deployed yet → nothing to do (the next deploy builds the env, KnativeManifestTrait).
     */
    public function syncProjectEnv(Project $project): void
    {
        $region = $project->knativeRegion;
        if (! $region?->kubeconfig) {
            return;
        }

        $k8s = $this->client($region);
        $namespace = self::namespaceFor($region);
        $bindings = $project->privateDatabaseBindings()->get();

        // Same names as KnativeDeployService::serviceName() / cronJobName()
        $targets = [
            ['serving.knative.dev/v1', 'Service', $project->slug, ['spec', 'template', 'spec', 'containers']],
            ['batch/v1', 'CronJob', $project->slug.'-trigger', ['spec', 'jobTemplate', 'spec', 'template', 'spec', 'containers']],
        ];

        foreach ($targets as [$apiVersion, $kind, $name, $path]) {
            $resource = $k8s->getResourceStatus($apiVersion, $kind, $name, $namespace);
            $container = $resource ? data_get($resource, implode('.', $path).'.0') : null;
            if (! is_array($container)) {
                continue;
            }

            $env = $container['env'] ?? [];
            $merged = SkupperManifests::mergeEnv($env, $bindings);
            if ($merged == $env) {
                continue;
            }

            $pointer = '/'.implode('/', $path).'/0/env';
            if (! $k8s->jsonPatch($apiVersion, $kind, $name, $namespace, [['op' => 'add', 'path' => $pointer, 'value' => $merged]])) {
                throw new SkupperException("Could not update the env of {$kind} {$name}.");
            }
        }
    }

    /**
     * {status, message} of a Skupper v2 resource (status.status, else the Ready condition).
     */
    public static function statusOf(array $resource): array
    {
        $status = $resource['status']['status'] ?? null;
        $message = $resource['status']['message'] ?? null;

        if ($status === null) {
            foreach ($resource['status']['conditions'] ?? [] as $condition) {
                if (($condition['type'] ?? null) === 'Ready') {
                    $status = ($condition['status'] ?? null) === 'True' ? 'Ready' : 'Pending';
                    $message ??= $condition['message'] ?? null;
                }
            }
        }

        return ['status' => $status ?? 'Pending', 'message' => $message];
    }
}
