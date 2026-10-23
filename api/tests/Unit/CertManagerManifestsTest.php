<?php

namespace Tests\Unit;

use App\Services\CertManager\CertManager;
use App\Services\Helpers\DedicatedWorkers;
use Tests\TestCase;

/**
 * What RunR installs as cert-manager in a region and the default ClusterIssuers it creates
 * (docs/regions.md#cert-manager): the pinned chart, every image from the reqs mirror (the mirror of
 * quay.io/jetstack, REQS_IMAGES of lib/mirror.sh in BRF-Tech/runr-runtimes), RunR's label on what
 * the chart renders, and the ACME / CA issuers.
 */
class CertManagerManifestsTest extends TestCase
{
    public function test_the_chart_is_pinned_and_its_images_come_from_the_reqs_mirror(): void
    {
        // A chart bump changes every image tag: mirror them first (REQS_IMAGES of lib/mirror.sh
        // in BRF-Tech/runr-runtimes); ci/check-reqs-mirror.sh fails while one does not resolve.
        $this->assertSame('v1.21.2', CertManager::CHART_VERSION);
        $this->assertSame([
            'controller' => 'ghcr.io/brf-tech/runr/reqs/cert-manager-controller:v1.21.2',
            'webhook' => 'ghcr.io/brf-tech/runr/reqs/cert-manager-webhook:v1.21.2',
            'cainjector' => 'ghcr.io/brf-tech/runr/reqs/cert-manager-cainjector:v1.21.2',
            'acmesolver' => 'ghcr.io/brf-tech/runr/reqs/cert-manager-acmesolver:v1.21.2',
        ], CertManager::images());
    }

    public function test_the_kind_e2e_loads_the_images_it_runs(): void
    {
        $harness = dirname(__DIR__, 3).'/runtime/lib/test.sh';
        if (! is_file($harness)) {
            $this->markTestSkipped('runtime/lib/test.sh is not part of this checkout');
        }
        $script = (string) file_get_contents($harness);

        foreach (['controller', 'webhook', 'cainjector'] as $component) {
            $this->assertStringContainsString('"'.CertManager::image($component).'"', $script, "preload_kind_images loads {$component}");
        }
    }

    public function test_the_helm_values(): void
    {
        $values = CertManager::helmValues();

        $this->assertSame(['enabled' => true, 'keep' => true], $values['crds'], 'CRDs with the release, kept when it goes');
        $this->assertSame(['app.kubernetes.io/part-of' => 'runr'], $values['global']['commonLabels'], 'the mark of RunR\'s installation');
        $this->assertSame(['enabled' => false], $values['startupapicheck'], 'no Job of a fifth image: RunR waits for the Deployments');
        $this->assertSame(['repository' => 'ghcr.io/brf-tech/runr/reqs/cert-manager-controller', 'tag' => 'v1.21.2'], $values['image']);
        $this->assertSame(['repository' => 'ghcr.io/brf-tech/runr/reqs/cert-manager-webhook', 'tag' => 'v1.21.2'], $values['webhook']['image']);
        $this->assertSame(['repository' => 'ghcr.io/brf-tech/runr/reqs/cert-manager-cainjector', 'tag' => 'v1.21.2'], $values['cainjector']['image']);
        $this->assertSame(['repository' => 'ghcr.io/brf-tech/runr/reqs/cert-manager-acmesolver', 'tag' => 'v1.21.2'], $values['acmesolver']['image']);
        $this->assertSame(['cpu' => '10m', 'memory' => '64Mi'], $values['resources']['requests']);
        $this->assertSame(['cpu' => '10m', 'memory' => '32Mi'], $values['webhook']['resources']['requests']);

        // a system component: tolerates the dedicated-worker taint, prefers the other nodes
        foreach ([$values, $values['webhook'], $values['cainjector']] as $component) {
            $this->assertSame([DedicatedWorkers::toleration()], $component['tolerations']);
            $this->assertSame(DedicatedWorkers::preferOtherNodes(), $component['affinity']);
        }
    }

    public function test_overrides_of_the_chart_version_and_the_image_prefix(): void
    {
        config(['knative.cert_manager.chart_version' => 'v1.21.3', 'knative.cert_manager.image_prefix' => 'registry.example.com/mirror/']);

        $this->assertSame('registry.example.com/mirror/cert-manager-webhook:v1.21.3', CertManager::image('webhook'));
        $this->assertSame(['repository' => 'registry.example.com/mirror/cert-manager-controller', 'tag' => 'v1.21.3'], CertManager::helmValues()['image']);

        // an image prefix under one of RunR's earlier namespaces moves to ghcr.io/brf-tech/runr
        config(['knative.cert_manager.chart_version' => null, 'knative.cert_manager.image_prefix' => 'ghcr.io/runrsh/reqs']);
        $this->assertSame('ghcr.io/brf-tech/runr/reqs/cert-manager-controller:v1.21.2', CertManager::image('controller'));
    }

    public function test_the_acme_issuer(): void
    {
        $issuer = CertManager::acmeIssuer('ops@example.org', CertManager::LETSENCRYPT, ['ingressClassName' => 'traefik']);

        $this->assertSame(['cert-manager.io/v1', 'ClusterIssuer', 'runr-acme'], [$issuer['apiVersion'], $issuer['kind'], $issuer['metadata']['name']]);
        $this->assertSame('runr', $issuer['metadata']['labels']['app.kubernetes.io/managed-by']);
        $acme = $issuer['spec']['acme'];
        $this->assertSame('https://acme-v02.api.letsencrypt.org/directory', $acme['server']);
        $this->assertSame('ops@example.org', $acme['email']);
        $this->assertSame(['name' => 'runr-acme-account'], $acme['privateKeySecretRef']);
        $ingress = $acme['solvers'][0]['http01']['ingress'];
        $this->assertSame('traefik', $ingress['ingressClassName']);
        $this->assertSame([DedicatedWorkers::toleration()], $ingress['podTemplate']['spec']['tolerations'], 'solver pods may land on a dedicated worker');

        // a region Traefik that does not own the IngressClass "traefik" watches the annotation class
        $other = CertManager::acmeIssuer('ops@example.org', CertManager::LETSENCRYPT, ['class' => 'traefik-knative']);
        $this->assertSame('traefik-knative', $other['spec']['acme']['solvers'][0]['http01']['ingress']['class']);
        $this->assertArrayNotHasKey('ingressClassName', $other['spec']['acme']['solvers'][0]['http01']['ingress']);
    }

    public function test_the_region_ca_chain(): void
    {
        $selfSigned = CertManager::selfSignedIssuer();
        $this->assertSame('runr-selfsigned', $selfSigned['metadata']['name']);
        $this->assertSame('{}', json_encode($selfSigned['spec']['selfSigned']), 'selfSigned: {} — an object, not a list');

        $ca = CertManager::caCertificate('cert-manager', CertManager::caCommonName('eu-1'));
        $this->assertSame(['Certificate', 'runr-region-ca', 'cert-manager'], [$ca['kind'], $ca['metadata']['name'], $ca['metadata']['namespace']]);
        $this->assertTrue($ca['spec']['isCA']);
        $this->assertSame('RunR region CA (eu-1)', $ca['spec']['commonName']);
        $this->assertSame('runr-region-ca', $ca['spec']['secretName']);
        $this->assertSame('87600h', $ca['spec']['duration']);
        $this->assertSame(['algorithm' => 'ECDSA', 'size' => 256], $ca['spec']['privateKey']);
        $this->assertSame(['name' => 'runr-selfsigned', 'kind' => 'ClusterIssuer', 'group' => 'cert-manager.io'], $ca['spec']['issuerRef']);

        $issuer = CertManager::caIssuer();
        $this->assertSame(['runr-ca', ['secretName' => 'runr-region-ca']], [$issuer['metadata']['name'], $issuer['spec']['ca']]);
    }

    public function test_issuer_names_and_their_kind(): void
    {
        $this->assertTrue(CertManager::isRunrIssuer('runr-acme'));
        $this->assertTrue(CertManager::isRunrIssuer('runr-ca'));
        $this->assertFalse(CertManager::isRunrIssuer('runr-selfsigned'), 'signs the region CA only');
        $this->assertFalse(CertManager::isRunrIssuer('letsencrypt-prod'));
        $this->assertFalse(CertManager::isRunrIssuer(null));

        $this->assertSame('acme', CertManager::issuerKind('runr-acme'));
        $this->assertSame('ca', CertManager::issuerKind('runr-ca'));
        $this->assertSame('own', CertManager::issuerKind('letsencrypt-prod'));
        $this->assertNull(CertManager::issuerKind(null));
        $this->assertNull(CertManager::issuerKind(''));
    }

    public function test_the_ready_condition(): void
    {
        $this->assertSame([false, null], CertManager::readyCondition(null));
        $this->assertSame([false, null], CertManager::readyCondition(['status' => []]));
        $this->assertSame([true, 'The ACME account was registered with the ACME server'], CertManager::readyCondition(['status' => ['conditions' => [
            ['type' => 'Ready', 'status' => 'True', 'reason' => 'ACMEAccountRegistered', 'message' => 'The ACME account was registered with the ACME server'],
        ]]]));
        $this->assertSame([false, 'Failed to register ACME account: 400 urn:ietf:params:acme:error:invalidContact'], CertManager::readyCondition(['status' => ['conditions' => [
            ['type' => 'Ready', 'status' => 'False', 'reason' => 'ErrRegisterACMEAccount', 'message' => 'Failed to register ACME account: 400 urn:ietf:params:acme:error:invalidContact'],
        ]]]));
    }
}
