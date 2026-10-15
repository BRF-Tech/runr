<?php

namespace Tests\Feature;

use App\Console\Commands\E2eTestCommand;
use App\Http\Resources\ProjectResource;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use Tests\TestCase;

/**
 * The Kind e2e (php artisan test:e2e) calls a function at the host the API reports for it
 * (ProjectResource endpoint_url): Knative's domain-template {{.Name}}.{{.Domain}} with the region's
 * domain, which already names the region. It used to build {slug}.{namespace}.{domain}, a host no
 * Traefik route matches ("404 page not found" for every function call of the suites).
 */
class E2eFunctionUrlTest extends TestCase
{
    private function region(array $settings): KnativeRegion
    {
        return (new KnativeRegion)->forceFill(['name' => 'eu', 'project_namespace' => 'eu', 'knative_settings' => $settings]);
    }

    private function e2eUrl(KnativeRegion $region, string $slug, string $path = ''): string
    {
        $command = app(E2eTestCommand::class);
        (new \ReflectionProperty($command, 'e2eRegion'))->setValue($command, $region);
        (new \ReflectionProperty($command, 'e2eNamespace'))->setValue($command, $region->project_namespace);

        return (new \ReflectionMethod($command, 'e2eFunctionUrl'))->invoke($command, $slug, $path);
    }

    public function test_the_e2e_calls_the_host_the_api_reports(): void
    {
        $region = $this->region(['knative_domain' => 'eu.runr.test']);
        $project = (new Project)->forceFill(['id' => '01J9ZPROJECT00000000000000', 'slug' => 'orders', 'service_type' => 'faas']);
        $project->setRelation('faasConfig', (new FaasConfig)->forceFill(['trigger_type' => 'http']));
        $project->setRelation('knativeRegion', $region);
        $reported = (new \ReflectionMethod(ProjectResource::class, 'resolveEndpointUrl'))->invoke(new ProjectResource($project));

        $this->assertSame('https://orders.eu.runr.test', $reported);
        $this->assertSame('http://orders.eu.runr.test/?op=all', $this->e2eUrl($region, 'orders', '/?op=all'));
        $this->assertSame(parse_url($reported, PHP_URL_HOST), parse_url($this->e2eUrl($region, 'orders'), PHP_URL_HOST));
    }

    public function test_without_a_domain_the_e2e_calls_the_cluster_local_service(): void
    {
        $this->assertSame('http://orders.eu.svc.cluster.local/hello', $this->e2eUrl($this->region([]), 'orders', '/hello'));
    }
}
