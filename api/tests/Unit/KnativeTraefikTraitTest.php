<?php

namespace Tests\Unit;

use App\Services\KnativeInstallService;
use App\Services\KnativeStatusService;
use PHPUnit\Framework\TestCase;

class KnativeTraefikTraitTest extends TestCase
{
    private const NS = 'knative-serving';

    private function ingressClass(string $name, ?string $release = null, ?string $releaseNs = null, bool $default = false): array
    {
        $annotations = [];
        if ($release) {
            $annotations['meta.helm.sh/release-name'] = $release;
            $annotations['meta.helm.sh/release-namespace'] = $releaseNs;
        }
        if ($default) {
            $annotations['ingressclass.kubernetes.io/is-default-class'] = 'true';
        }

        return ['metadata' => ['name' => $name, 'annotations' => $annotations]];
    }

    private function ingress(string $ns, string $name, ?string $class): array
    {
        return [
            'metadata' => ['namespace' => $ns, 'name' => $name],
            'spec' => $class === null ? [] : ['ingressClassName' => $class],
        ];
    }

    public function test_fresh_cluster_keeps_default_traefik_class(): void
    {
        $values = KnativeInstallService::resolveTraefikIngressClassValues([], self::NS);

        $this->assertSame(['ingressClass' => ['enabled' => true, 'isDefaultClass' => true]], $values);
    }

    public function test_own_traefik_class_is_kept_on_reinstall(): void
    {
        $values = KnativeInstallService::resolveTraefikIngressClassValues([
            $this->ingressClass('traefik', 'traefik', self::NS, true),
        ], self::NS);

        $this->assertTrue($values['ingressClass']['enabled']);
        $this->assertTrue($values['ingressClass']['isDefaultClass']);
        $this->assertArrayNotHasKey('providers', $values);
    }

    public function test_foreign_traefik_class_switches_to_traefik_knative(): void
    {
        // e.g. the Traefik of a managed platform / k3s (release traefik in kube-system)
        $values = KnativeInstallService::resolveTraefikIngressClassValues([
            $this->ingressClass('traefik', 'traefik', 'kube-system', true),
        ], self::NS);

        $this->assertFalse($values['ingressClass']['enabled']);
        $this->assertSame('traefik-knative', $values['providers']['kubernetesIngress']['ingressClass']);
    }

    public function test_unmanaged_traefik_class_is_foreign(): void
    {
        $values = KnativeInstallService::resolveTraefikIngressClassValues([
            $this->ingressClass('traefik'),
        ], self::NS);

        $this->assertFalse($values['ingressClass']['enabled']);
    }

    public function test_other_default_class_is_not_taken_over(): void
    {
        $values = KnativeInstallService::resolveTraefikIngressClassValues([
            $this->ingressClass('nginx', null, null, true),
        ], self::NS);

        $this->assertTrue($values['ingressClass']['enabled']);
        $this->assertFalse($values['ingressClass']['isDefaultClass']);
    }

    public function test_values_merge_into_helm_values(): void
    {
        $base = [
            'ingressClass' => ['enabled' => true, 'isDefaultClass' => true],
            'providers' => ['kubernetesIngress' => ['enabled' => true, 'allowExternalNameServices' => true]],
        ];
        $merged = array_replace_recursive($base, KnativeInstallService::resolveTraefikIngressClassValues([
            $this->ingressClass('traefik', 'traefik', 'kube-system'),
        ], self::NS));

        $this->assertSame(['enabled' => false, 'isDefaultClass' => false], $merged['ingressClass']);
        $this->assertSame([
            'enabled' => true, 'allowExternalNameServices' => true, 'ingressClass' => 'traefik-knative',
        ], $merged['providers']['kubernetesIngress']);
    }

    public function test_console_ingress_keeps_traefik(): void
    {
        $served = KnativeInstallService::resolveIngressesServedByTraefik(
            [$this->ingressClass('traefik', 'traefik', self::NS, true)],
            [
                $this->ingress('runr', 'runr', 'traefik'),
                $this->ingress('other', 'no-class', null),
                $this->ingress('other', 'nginx-app', 'nginx'),
            ],
            self::NS,
        );

        $this->assertSame(['runr/runr', 'other/no-class'], $served);
    }

    public function test_foreign_class_ingresses_do_not_keep_traefik(): void
    {
        $served = KnativeInstallService::resolveIngressesServedByTraefik(
            [$this->ingressClass('traefik', 'traefik', 'kube-system', true)],
            [
                $this->ingress('runr', 'runr', 'traefik'),
                $this->ingress('other', 'no-class', null),
            ],
            self::NS,
        );

        $this->assertSame([], $served);
    }

    public function test_traefik_knative_class_ingress_keeps_traefik(): void
    {
        $served = KnativeInstallService::resolveIngressesServedByTraefik(
            [],
            [$this->ingress('apps', 'app', 'traefik-knative')],
            self::NS,
        );

        $this->assertSame(['apps/app'], $served);
    }

    public function test_serving_version_from_cr(): void
    {
        $this->assertSame('1.21.0', KnativeStatusService::versionFromServingCr([
            'spec' => ['version' => '1.21'], 'status' => ['version' => 'v1.21.0'],
        ]));
        $this->assertSame('1.21', KnativeStatusService::versionFromServingCr(['spec' => ['version' => '1.21']]));
        $this->assertNull(KnativeStatusService::versionFromServingCr(null));
    }
}
