<?php

namespace App\Console\Commands\Concerns;

use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Models\ProjectDomain;
use App\Models\User;
use App\Services\CertManager\CertManager;
use App\Services\CertManager\CertManagerService;
use App\Services\GatewayService;
use App\Services\KubernetesClient;
use App\Services\Manifests\ProjectDomainManifests;
use App\Services\ProjectDomainService;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\Steps\CertManagerStep;

/**
 * E2E of cert-manager in a region (test:e2e --suite=cert-manager, runtime/lib/test-cert-manager.sh),
 * on the e2e region's Kind cluster (docs/regions.md#cert-manager):
 *  - cm-installed     the Serving install set cert-manager up: GET …/cert-manager reaches "ready"
 *                     (the status read finishes an install that waited for cert-manager to start),
 *                     RunR's release, this release's chart, every Deployment on the reqs mirror's image
 *  - cm-issuer-ca     the Kind region is not reachable from the internet: the default issuer is the
 *                     region CA (runr-ca), Ready; the CA download is a CA certificate (CA:TRUE), no key
 *  - cm-domain-https  a custom domain of an HTTP function starts in TLS mode cert-manager; routed
 *                     (DNS proof skipped: the domain is marked verified), its Certificate becomes
 *                     Ready and HTTPS through the region Traefik answers 200 with a certificate
 *                     that verifies against the downloaded region CA
 *  - cm-gateway-https a gateway with a custom domain (marked verified) and a route to the function:
 *                     its Certificate from the region issuer becomes Ready and HTTPS on the custom
 *                     domain through the region Traefik answers the route with 200, verified against
 *                     the region CA; the gateway is removed afterwards
 *  - cm-reconcile     region:reconcile's cert-manager step finds nothing to change
 *  - cm-remove        removing cert-manager is refused while the domain has its certificate; with
 *                     the domain gone RunR's release, issuers and namespace go, the CRDs stay
 *  - cm-reinstall     installing again through the API brings it back to "ready" with the issuer
 */
trait E2eTestCertManagerTrait
{
    private const CM_DOMAIN = 'cm-e2e.example.test';

    private const CM_GATEWAY_DOMAIN = 'cm-gw-e2e.example.test';

    private const CM_PYTHON = <<<'PY'
def handler(event, context):
    return {"statusCode": 200, "body": {"ok": True, "via": "cert-manager"}}
PY;

    private function runCertManagerSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── cert-manager (install, region CA issuer, custom-domain HTTPS, removal) ──');

        $k8s = $this->getE2eKubernetesClient();
        $tests = [];
        $tests[] = $this->recCheck('cm-installed', fn () => $this->cmInstalled($regionId, $k8s));
        $ca = null;
        $tests[] = $this->recCheck('cm-issuer-ca', function () use ($regionId, &$ca) {
            [$result, $ca] = $this->cmIssuerCa($regionId);

            return $result;
        });

        $fn = $this->makeTestEntry('cm-fn', ['service_type' => 'xaas', 'runtime' => 'python', 'runtime_version' => null,
            'trigger_type' => 'http', 'code' => self::CM_PYTHON], $regionId);
        $functions = [$fn];
        $this->runTestsSequentially($functions);
        $fn = $functions[0];
        $fn['steps']['verify'] = $fn['steps']['ready'] === true ? true : 'skipped';
        $tests[] = $fn;

        $domainId = null;
        $tests[] = $this->recCheck('cm-domain-https', function () use ($fn, $ca, $k8s, &$domainId) {
            if ($fn['steps']['ready'] !== true || $ca === null) {
                return 'needs the function and the region CA';
            }

            return $this->cmDomainHttps($fn, $ca, $k8s, $domainId);
        });
        $tests[] = $this->recCheck('cm-gateway-https', function () use ($fn, $ca, $k8s, $regionId) {
            if ($fn['steps']['ready'] !== true || $ca === null) {
                return 'needs the function and the region CA';
            }

            return $this->cmGatewayHttps($fn, $ca, $k8s, $regionId);
        });
        $tests[] = $this->recCheck('cm-reconcile', function () use ($regionId, $k8s) {
            $lines = (new CertManagerStep)->run(new ClusterGroup('e2e', [new ReconcileTarget(KnativeRegion::findOrFail($regionId))], $k8s), false);

            return $lines === [] ?: 'the step changes what this release installed: '.implode(' | ', $lines);
        });
        $tests[] = $this->recCheck('cm-remove', fn () => $this->cmRemove($regionId, $fn, $domainId, $k8s));
        $tests[] = $this->recCheck('cm-reinstall', function () use ($regionId) {
            $queued = $this->api()->post("/api/knative-regions/{$regionId}/cert-manager");
            if ($queued->status() !== 202) {
                return "install answered {$queued->status()}: ".$queued->body();
            }
            $state = $this->cmWaitStatus($regionId, ['ready', 'error'], 420);

            return ($state['status'] ?? null) === 'ready' && ($state['issuer']['name'] ?? null) === CertManager::CA_ISSUER
                ?: 'after the install: '.($state['status'] ?? 'no status').' — '.CertManagerService::lastLine((string) ($state['message'] ?? '')).' (issuer '.json_encode($state['issuer'] ?? null).')';
        });

        return $tests;
    }

    /**
     * GET …/cert-manager until its status is one of $statuses (the read also has an install that
     * waits for cert-manager to start finished in the background).
     *
     * @param  list<string>  $statuses
     */
    private function cmWaitStatus(string $regionId, array $statuses, int $seconds): array
    {
        $deadline = time() + $seconds;
        $state = [];
        while (time() < $deadline) {
            $state = (array) $this->api()->get("/api/knative-regions/{$regionId}/cert-manager")->json();
            if (in_array($state['status'] ?? null, $statuses, true)) {
                return $state;
            }
            sleep(5);
        }

        return $state;
    }

    private function cmInstalled(string $regionId, KubernetesClient $k8s): bool|string
    {
        $state = $this->cmWaitStatus($regionId, ['ready', 'error', 'not_installed'], 420);
        if (($state['status'] ?? null) !== 'ready') {
            return 'cert-manager after the Serving install: '.json_encode(array_intersect_key($state, array_flip(['status', 'message'])));
        }

        $problems = [];
        if (($state['managed'] ?? null) !== true) {
            $problems[] = 'not RunR\'s installation';
        }
        if (($state['version'] ?? null) !== CertManager::chartVersion()) {
            $problems[] = 'version '.($state['version'] ?? '-');
        }
        foreach (CertManager::DEPLOYMENTS as $component => $name) {
            $deployment = $k8s->getResourceStatus('apps/v1', 'Deployment', $name, CertManager::NAMESPACE);
            $image = (string) ($deployment['spec']['template']['spec']['containers'][0]['image'] ?? '');
            if ($image !== CertManager::image($component)) {
                $problems[] = "{$name} runs ".($image ?: 'nothing');
            }
            if (($deployment['metadata']['labels']['app.kubernetes.io/part-of'] ?? null) !== 'runr') {
                $problems[] = "{$name} without RunR's label";
            }
        }
        $this->line('    cert-manager '.($state['version'] ?? '-').' ready, ClusterIssuer '.($state['issuer']['name'] ?? '-'));

        return $problems === [] ?: implode('; ', $problems);
    }

    /** @return array{0: bool|string, 1: ?string} [result, the region CA's PEM] */
    private function cmIssuerCa(string $regionId): array
    {
        $state = (array) $this->api()->get("/api/knative-regions/{$regionId}/cert-manager")->json();
        if (($state['issuer']['name'] ?? null) !== CertManager::CA_ISSUER) {
            return ['issuer '.json_encode($state['issuer'] ?? null).' (plan: '.($state['plan']['reason'] ?? '-').')', null];
        }
        if (($state['cluster']['issuer_ready'] ?? false) !== true) {
            return ['runr-ca not Ready: '.($state['cluster']['issuer_message'] ?? '-'), null];
        }

        $download = $this->api()->get("/api/knative-regions/{$regionId}/cert-manager/ca-certificate");
        $pem = $download->body();
        if (! $download->successful() || str_contains($pem, 'PRIVATE KEY')) {
            return ["CA download answered {$download->status()}", null];
        }
        $parsed = openssl_x509_parse($pem);
        if (! $parsed || ! str_contains((string) ($parsed['extensions']['basicConstraints'] ?? ''), 'CA:TRUE')) {
            return ['the download is no CA certificate', null];
        }
        $this->line('    region CA: '.($parsed['subject']['CN'] ?? '-').', valid until '.date('Y-m-d', (int) ($parsed['validTo_time_t'] ?? 0)));

        return [true, $pem];
    }

    private function cmDomainHttps(array $fn, string $ca, KubernetesClient $k8s, ?string &$domainId): bool|string
    {
        $added = $this->api()->post("/api/projects/{$fn['projectId']}/domains", ['domain' => self::CM_DOMAIN]);
        if ($added->status() !== 201) {
            return "adding the domain answered {$added->status()}: ".$added->body();
        }
        $domainId = (string) $added->json('data.id');
        if ($added->json('data.tls_mode') !== 'cert-manager') {
            return 'a new domain starts in TLS mode '.$added->json('data.tls_mode');
        }

        // No DNS in Kind: the domain is taken as verified, then routed like a verified one
        $domain = ProjectDomain::query()->findOrFail($domainId);
        $domain->update(['status' => ProjectDomain::STATUS_VERIFIED]);
        if (! app(ProjectDomainService::class)->activate($domain->fresh())) {
            return 'routing failed: '.$domain->fresh()->status_message;
        }

        $ns = $this->getE2eNamespace();
        $name = ProjectDomainManifests::resourceName($fn['projectId'], self::CM_DOMAIN);
        $ready = false;
        $message = null;
        for ($i = 0; $i < 40 && ! $ready; $i++) {
            [$ready, $message] = CertManager::readyCondition($k8s->getResourceStatus(CertManager::API, 'Certificate', $name, $ns));
            if (! $ready) {
                sleep(3);
            }
        }
        if (! $ready) {
            return "Certificate {$name} not Ready: ".($message ?? '-');
        }

        // HTTPS through the region Traefik, verified against the region CA
        $servingNs = $this->getE2eRegion()?->knative_serving_namespace ?: 'knative-serving';
        $traefik = (string) ($k8s->getResourceStatus('v1', 'Service', 'traefik', $servingNs)['spec']['clusterIP'] ?? '');
        if ($traefik === '') {
            return 'no ClusterIP of the region Traefik';
        }
        $k8s->applyResource(['apiVersion' => 'v1', 'kind' => 'ConfigMap', 'metadata' => ['name' => 'cm-e2e-ca', 'namespace' => $ns], 'data' => ['ca.crt' => $ca]], $ns);
        $result = $this->recRunPod($k8s, $ns, 'cm-e2e-https', [
            'containers' => [[
                'name' => 'curl',
                'image' => 'ghcr.io/brf-tech/runr/reqs/curl:latest',
                'command' => ['sh', '-c', 'code=$(curl -sS --max-time 60 --retry 5 --retry-delay 3 --retry-all-errors --cacert /ca/ca.crt '
                    .'--resolve '.self::CM_DOMAIN.":443:{$traefik} -o /tmp/body -w '%{http_code}' https://".self::CM_DOMAIN.'/) '
                    .'&& cat /tmp/body && echo " HTTP $code" && [ "$code" = 200 ]'],
                'volumeMounts' => [['name' => 'ca', 'mountPath' => '/ca']],
            ]],
            'volumes' => [['name' => 'ca', 'configMap' => ['name' => 'cm-e2e-ca']]],
        ]);
        $k8s->deleteResource('v1', 'ConfigMap', 'cm-e2e-ca', $ns);

        return $result === true ? true : "HTTPS with the region CA: {$result}";
    }

    /**
     * A gateway's custom domain gets its TLS from cert-manager like a project's (GatewayService::
     * syncCustomDomain): a Certificate from the region's issuer, served by the domain's IngressRoute.
     */
    private function cmGatewayHttps(array $fn, string $ca, KubernetesClient $k8s, string $regionId): bool|string
    {
        $user = User::query()->where('username', config('app.admin.username'))->first() ?? User::query()->firstOrFail();
        $gateway = Gateway::query()->create(['user_id' => $user->id, 'knative_region_id' => $regionId, 'subdomain' => 'cmgw', 'status' => 'active']);
        $service = app(GatewayService::class);

        try {
            GatewayRoute::query()->create(['gateway_id' => $gateway->id, 'project_id' => $fn['projectId'], 'path' => '/cm', 'strip_prefix' => true, 'sort_order' => 1]);
            // No DNS in Kind: the domain is taken as verified (GatewayDomains::verify sets custom_domain)
            $gateway->forceFill(['custom_domain' => self::CM_GATEWAY_DOMAIN])->save();
            $service->syncToCluster($gateway->fresh(['routes.project']));

            $ns = $this->getE2eNamespace();
            $name = $service->customDomainRouteName($gateway);
            $ready = false;
            $message = null;
            for ($i = 0; $i < 40 && ! $ready; $i++) {
                [$ready, $message] = CertManager::readyCondition($k8s->getResourceStatus(CertManager::API, 'Certificate', $name, $ns));
                if (! $ready) {
                    sleep(3);
                }
            }
            if (! $ready) {
                return "Certificate {$name} of the gateway's domain not Ready: ".($message ?? '-').' (gateway '.$gateway->fresh()->status.': '.$gateway->fresh()->status_message.')';
            }

            $servingNs = $this->getE2eRegion()?->knative_serving_namespace ?: 'knative-serving';
            $traefik = (string) ($k8s->getResourceStatus('v1', 'Service', 'traefik', $servingNs)['spec']['clusterIP'] ?? '');
            if ($traefik === '') {
                return 'no ClusterIP of the region Traefik';
            }
            $k8s->applyResource(['apiVersion' => 'v1', 'kind' => 'ConfigMap', 'metadata' => ['name' => 'cm-e2e-gw-ca', 'namespace' => $ns], 'data' => ['ca.crt' => $ca]], $ns);
            $result = $this->recRunPod($k8s, $ns, 'cm-e2e-gw-https', [
                'containers' => [[
                    'name' => 'curl',
                    'image' => 'ghcr.io/brf-tech/runr/reqs/curl:latest',
                    'command' => ['sh', '-c', 'code=$(curl -sS --max-time 60 --retry 5 --retry-delay 3 --retry-all-errors --cacert /ca/ca.crt '
                        .'--resolve '.self::CM_GATEWAY_DOMAIN.":443:{$traefik} -o /tmp/body -w '%{http_code}' https://".self::CM_GATEWAY_DOMAIN.'/cm/) '
                        .'&& cat /tmp/body && echo " HTTP $code" && [ "$code" = 200 ]'],
                    'volumeMounts' => [['name' => 'ca', 'mountPath' => '/ca']],
                ]],
                'volumes' => [['name' => 'ca', 'configMap' => ['name' => 'cm-e2e-gw-ca']]],
            ]);
            $k8s->deleteResource('v1', 'ConfigMap', 'cm-e2e-gw-ca', $ns);

            return $result === true ? true : "HTTPS of the gateway's custom domain with the region CA: {$result}";
        } finally {
            $service->teardown($gateway);
            GatewayRoute::query()->where('gateway_id', $gateway->id)->delete();
            $gateway->delete();
        }
    }

    private function cmRemove(string $regionId, array $fn, ?string $domainId, KubernetesClient $k8s): bool|string
    {
        if ($domainId !== null) {
            $refused = $this->api()->delete("/api/knative-regions/{$regionId}/cert-manager");
            if ($refused->status() !== 422) {
                return "removal while the domain has its certificate answered {$refused->status()}";
            }
            $deleted = $this->api()->delete("/api/projects/{$fn['projectId']}/domains/{$domainId}");
            if ($deleted->status() !== 204) {
                return "removing the domain answered {$deleted->status()}";
            }
        }

        $queued = $this->api()->delete("/api/knative-regions/{$regionId}/cert-manager");
        if ($queued->status() !== 202) {
            return "removal answered {$queued->status()}: ".$queued->body();
        }
        $state = $this->cmWaitStatus($regionId, ['not_installed', 'error', 'ready'], 300);
        if (($state['status'] ?? null) !== 'not_installed') {
            return 'after the removal: '.json_encode(array_intersect_key($state, array_flip(['status', 'message'])));
        }

        $problems = [];
        for ($i = 0; $i < 40 && $k8s->getResourceStatus('apps/v1', 'Deployment', 'cert-manager', CertManager::NAMESPACE) !== null; $i++) {
            sleep(3);
        }
        if ($k8s->getResourceStatus('apps/v1', 'Deployment', 'cert-manager', CertManager::NAMESPACE) !== null) {
            $problems[] = 'RunR\'s cert-manager still runs';
        }
        if ($k8s->getClusterResource(CertManager::API, 'ClusterIssuer', CertManager::CA_ISSUER) !== null) {
            $problems[] = 'ClusterIssuer runr-ca is left';
        }
        if ($k8s->getClusterResource('apiextensions.k8s.io/v1', 'CustomResourceDefinition', 'certificates.cert-manager.io') === null) {
            $problems[] = 'the CRDs went with the release (crds.keep)';
        }

        return $problems === [] ?: implode('; ', $problems);
    }
}
