<?php

namespace Tests\Feature\Secrets;

use App\Jobs\ExternalSecretsJob;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\RegionSecretSetting;
use App\Models\SecretStore;
use App\Models\User;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\Secrets\External\ExternalSecretsInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * External Secrets Operator as an optional region feature (docs/secret-stores.md): status,
 * install and removal through the API (queued), the pinned chart with RunR's image mirror and
 * namespaced stores only, and no removal while projects of the region have references.
 */
class ExternalSecretsRegionApiTest extends TestCase
{
    use ActsWithRoles, FakesRegionSecrets, RefreshDatabase;

    private User $owner;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->userWithRole('owner');
        $this->region = $this->regionWithCluster('fn');
    }

    private function url(): string
    {
        return "/api/knative-regions/{$this->region->id}/external-secrets";
    }

    public function test_install_and_removal_are_queued(): void
    {
        Queue::fake();

        $this->signedIn($this->owner)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.status', 'not_installed')
            ->assertJsonPath('data.release_chart_version', config('secrets.external.chart_version'));

        $this->signedIn($this->owner)->postJson($this->url())->assertAccepted()->assertJsonPath('data.status', 'installing');
        Queue::assertPushed(ExternalSecretsJob::class, fn ($job) => $job->action === ExternalSecretsJob::INSTALL && $job->region->is($this->region));

        // One operation at a time
        $this->signedIn($this->owner)->postJson($this->url())->assertUnprocessable();

        RegionSecretSetting::forRegion($this->region)->fill(['eso_status' => RegionSecretSetting::ESO_READY])->save();
        $this->signedIn($this->owner)->deleteJson($this->url())->assertAccepted()->assertJsonPath('data.status', 'uninstalling');
        Queue::assertPushed(ExternalSecretsJob::class, fn ($job) => $job->action === ExternalSecretsJob::UNINSTALL);
    }

    public function test_the_operator_stays_while_projects_of_the_region_have_references(): void
    {
        Queue::fake();
        RegionSecretSetting::forRegion($this->region)->fill(['eso_status' => RegionSecretSetting::ESO_READY])->save();
        $store = SecretStore::create(['name' => 'vault', 'provider' => 'vault', 'auth' => 'kubernetes', 'settings' => ['server' => 'https://vault.test', 'role' => 'r']]);
        $project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        ProjectSecret::create(['project_id' => $project->id, 'name' => 'DB_URL', 'source' => 'external', 'secret_store_id' => $store->id, 'remote_path' => 'p']);

        $this->signedIn($this->owner)->getJson($this->url())->assertJsonPath('data.references', 1);
        $this->signedIn($this->owner)->deleteJson($this->url())->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_a_region_without_a_cluster_or_a_demo_region_gets_none(): void
    {
        $demo = KnativeRegion::create(['name' => 'demo', 'is_active' => true, 'is_demo' => true]);

        $this->signedIn($this->owner)->postJson("/api/knative-regions/{$demo->id}/external-secrets")->assertUnprocessable();
    }

    public function test_developers_see_the_status_but_do_not_install(): void
    {
        $member = $this->userWithRole('developer');

        $this->signedIn($member)->getJson($this->url())->assertOk()->assertJsonPath('data.can_manage', false);
        $this->signedIn($member)->postJson($this->url())->assertForbidden();
    }

    public function test_the_install_pins_the_chart_and_mirrors_the_image(): void
    {
        $helm = Mockery::mock(HelmService::class);
        $helm->shouldReceive('repoAdd')->once()->with('external-secrets', config('secrets.external.chart_repo'))->andReturn(new HelmResult(true, '', '', 0));
        $helm->shouldReceive('repoUpdate')->once()->andReturn(new HelmResult(true, '', '', 0));
        $helm->shouldReceive('install')->once()->withArgs(function (...$args) {
            [$release, $chart, $namespace, , , $version, $values] = $args + [6 => []];
            $image = config('secrets.external.image');

            return $release === 'external-secrets'
                && $chart === 'external-secrets/external-secrets'
                && $namespace === 'external-secrets'
                && $version === config('secrets.external.chart_version')
                && $values['installCRDs'] === true
                && $values['image']['repository'] === 'ghcr.io/brf-tech/runr/reqs/external-secrets'
                && str_ends_with((string) $image, ':'.$values['image']['tag'])
                && $values['webhook']['image'] === $values['image']
                && $values['certController']['image'] === $values['image']
                && $values['processClusterStore'] === false
                && $values['processPushSecret'] === false
                && $values['processClusterPushSecret'] === false;
        })->andReturn(new HelmResult(true, 'deployed', '', 0));
        $this->app->instance(HelmService::class, $helm);

        $settings = app(ExternalSecretsInstallService::class)->install($this->region);

        $this->assertSame(RegionSecretSetting::ESO_INSTALLING, $settings->eso_status);
        $this->assertSame(config('secrets.external.chart_version'), $settings->eso_chart_version);
        $this->assertNotNull($settings->eso_installed_at);
    }

    public function test_a_failed_install_says_why(): void
    {
        $helm = Mockery::mock(HelmService::class);
        $helm->shouldReceive('repoAdd', 'repoUpdate')->andReturn(new HelmResult(true, '', '', 0));
        $helm->shouldReceive('install')->andReturn(new HelmResult(false, '', 'chart not found', 1));
        $this->app->instance(HelmService::class, $helm);

        try {
            app(ExternalSecretsInstallService::class)->install($this->region);
            $this->fail('the install should fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('chart not found', $e->getMessage());
        }

        $settings = RegionSecretSetting::forRegion($this->region);
        $this->assertSame(RegionSecretSetting::ESO_ERROR, $settings->eso_status);
        $this->assertStringContainsString('chart not found', (string) $settings->eso_message);
    }
}
