<?php

namespace Tests\Unit\GlobalDns;

use App\Services\GlobalProjects\GlobalRouteManifests;
use PHPUnit\Framework\TestCase;

/**
 * The routes of a global project's host name in a region and the region's health route
 * (docs/global-projects.md): custom-domain style IngressRoutes that re-enter the region Traefik
 * with the Knative Service's public host, the wildcard certificate on websecure, the client's
 * address checked first in access mode allowlist, and the agent's global-health listener behind
 * runr-health.<zone>.
 */
class GlobalRouteManifestsTest extends TestCase
{
    public function test_the_project_routes_rewrite_the_host_and_carry_the_global_label(): void
    {
        [$middleware, $web, $secure] = GlobalRouteManifests::projectRoutes('01JPROJECT', 'shop', 'shop.g.example.com', 'fn', 'traefik', 'knative-serving', true, 'shop.eu.runr.test');
        $name = GlobalRouteManifests::resourceName('01JPROJECT');

        $this->assertSame($name, $middleware['metadata']['name']);
        // the public host: cluster-local hosts answer on the private entry point only
        $this->assertSame('shop.eu.runr.test', $middleware['spec']['headers']['customRequestHeaders']['Host']);
        $this->assertSame('shop.g.example.com', $middleware['spec']['headers']['customRequestHeaders']['K-Original-Host']);

        $this->assertSame(['web'], $web['spec']['entryPoints']);
        $this->assertSame('Host(`shop.g.example.com`)', $web['spec']['routes'][0]['match']);
        $this->assertSame(['name' => 'traefik', 'namespace' => 'knative-serving', 'port' => 80], $web['spec']['routes'][0]['services'][0]);

        $this->assertSame("{$name}-tls", $secure['metadata']['name']);
        $this->assertSame(['websecure'], $secure['spec']['entryPoints']);
        $this->assertSame(['secretName' => 'runr-global-wildcard'], $secure['spec']['tls']);

        foreach ([$middleware, $web, $secure] as $manifest) {
            $this->assertSame('true', $manifest['metadata']['labels']['runr/global-project']);
            $this->assertSame('01JPROJECT', $manifest['metadata']['labels']['runr/project-id']);
        }
    }

    public function test_without_a_certificate_https_answers_with_the_region_default(): void
    {
        $secure = GlobalRouteManifests::projectRoutes('01JPROJECT', 'shop', 'shop.g.example.com', 'fn', 'traefik', 'knative-serving', false, 'shop.eu.runr.test')[2];

        $this->assertEquals(new \stdClass, $secure['spec']['tls']);
    }

    public function test_access_mode_allowlist_checks_the_client_first(): void
    {
        [, $web, $secure] = GlobalRouteManifests::projectRoutes('01JPROJECT', 'shop', 'shop.g.example.com', 'fn', 'traefik', 'knative-serving', true, 'shop.eu.runr.test', true);

        foreach ([$web, $secure] as $route) {
            $rule = $route['spec']['routes'][0];
            $this->assertSame(['name' => 'runr-access-shop', 'namespace' => 'fn'], $rule['middlewares'][0]);
            $this->assertSame('TraefikService', $rule['services'][0]['kind']);
        }
    }

    public function test_the_health_route_leads_to_the_agent(): void
    {
        $route = GlobalRouteManifests::healthRoute('runr-health.g.example.com', 'fn', GlobalRouteManifests::AGENT_HEALTH_SERVICE, 'observability', 8086);

        $this->assertSame('runr-global-health', $route['metadata']['name']);
        $this->assertSame(['web'], $route['spec']['entryPoints']);
        $this->assertSame('Host(`runr-health.g.example.com`) && PathPrefix(`/global-health/`)', $route['spec']['routes'][0]['match']);
        $this->assertSame(['name' => 'runr-agent-health', 'namespace' => 'observability', 'port' => 8086], $route['spec']['routes'][0]['services'][0]);
    }
}
