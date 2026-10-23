<?php

namespace Tests\Feature\Secrets;

use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\User;
use App\Models\Version;
use App\Services\KnativeDeployService;
use App\Services\Secrets\SecretFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesOpenBao;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * The region's own OpenBao as the third place a project secret lives (source "openbao",
 * docs/secret-stores.md "OpenBao in the region"): new values go there while it runs, RunR keeps
 * metadata only, the workload reads them through the project's SecretStore (its own service
 * account and OpenBao role) and the ExternalSecret <slug>-bao-env.
 */
class OpenBaoSecretsTest extends TestCase
{
    use ActsWithRoles, FakesOpenBao, FakesRegionSecrets, RefreshDatabase;

    private KnativeRegion $region;

    private Project $project;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRegionSecrets();
        $this->fakeOpenBao();
        // The cluster's API (force-sync of the ExternalSecret, applies): answers yes
        Http::fake(fn () => Http::response(['kind' => 'Status'], 200));

        $this->region = $this->regionWithCluster('fn');
        $this->openBaoReady($this->region);
        $this->project = Project::create(['name' => 'orders', 'service_type' => 'caas']);
        $this->project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $this->project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128,
            'timeout_seconds' => 30, 'trigger_type' => 'schedule', 'schedule' => '*/5 * * * *',
        ]);
        $this->member = $this->userWithRole('developer');
    }

    private function path(): string
    {
        return 'projects/'.strtolower($this->project->id);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/projects/{$this->project->id}/secrets{$suffix}";
    }

    public function test_a_value_goes_into_the_regions_openbao(): void
    {
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'pw-in-bao'])
            ->assertCreated()
            ->assertJsonPath('data.source', 'openbao')
            ->assertJsonPath('data.stored_in', 'openbao')
            ->assertDontSee('pw-in-bao');

        $this->assertSame(['DB_PASS' => 'pw-in-bao'], $this->openBao($this->region)->kv[$this->path()]);
        $this->assertNull($this->regionSecrets->values($this->region, 'orders-secret-env'), 'not in a Kubernetes Secret');
        $row = DB::table('project_secrets')->first();
        $this->assertNull($row->value);
        $this->assertTrue(SecretFingerprint::matches('pw-in-bao', $row->fingerprint));

        // Read back from OpenBao
        $this->signedIn($this->userWithRole('owner'))->getJson($this->url('/DB_PASS/value'))
            ->assertOk()->assertJsonPath('value', 'pw-in-bao')->assertJsonPath('matches_fingerprint', true);

        // Removed while nothing runs: gone from OpenBao at once
        $this->signedIn($this->member)->deleteJson($this->url('/DB_PASS'))->assertOk();
        $this->assertSame([], $this->openBao($this->region)->kv[$this->path()]);
    }

    public function test_without_openbao_as_default_values_go_to_the_kubernetes_secret(): void
    {
        $this->openBaoReady($this->region, false);

        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'pw'])
            ->assertCreated()->assertJsonPath('data.source', 'region');

        $this->assertSame(['DB_PASS' => 'pw'], $this->regionSecrets->values($this->region, 'orders-secret-env'));
        $this->assertArrayNotHasKey($this->path(), $this->openBao($this->region)->kv);
    }

    public function test_an_openbao_that_does_not_answer_changes_nothing(): void
    {
        $this->openBao($this->region)->unreachable = true;

        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'pw'])
            ->assertStatus(503)->assertJsonPath('reason', 'region_unreachable');

        $this->assertSame(0, ProjectSecret::count());
    }

    public function test_the_workload_reads_its_values_through_its_own_identity(): void
    {
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'pw'])->assertCreated();
        $version = Version::create(['project_id' => $this->project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        $manifests = collect(app(KnativeDeployService::class)->buildManifests($this->project->fresh(), $version));
        $id = strtolower($this->project->id);

        $account = $manifests->first(fn ($m) => $m['kind'] === 'ServiceAccount' && $m['metadata']['name'] === "runr-bao-{$id}");
        $this->assertNotNull($account);
        $store = $manifests->first(fn ($m) => $m['kind'] === 'SecretStore' && $m['metadata']['name'] === "runr-bao-{$id}");
        $vault = $store['spec']['provider']['vault'];
        $this->assertSame('https://openbao.runr-openbao.svc:8200', $vault['server']);
        $this->assertSame(['mountPath' => 'kubernetes', 'role' => "runr-project-{$id}", 'serviceAccountRef' => ['name' => "runr-bao-{$id}"]], $vault['auth']['kubernetes']);
        $this->assertStringContainsString('BEGIN CERTIFICATE', base64_decode($vault['caBundle']));

        $external = $manifests->first(fn ($m) => $m['kind'] === 'ExternalSecret' && $m['metadata']['name'] === 'orders-bao-env');
        $this->assertSame([['secretKey' => 'DB_PASS', 'remoteRef' => ['key' => "projects/{$id}", 'property' => 'DB_PASS']]], $external['spec']['data']);
        $this->assertSame("runr-bao-{$id}", $external['spec']['secretStoreRef']['name']);

        $cron = $manifests->firstWhere('kind', 'CronJob');
        $env = collect($cron['spec']['jobTemplate']['spec']['template']['spec']['containers'][0]['env'])->keyBy('name');
        $this->assertSame(['name' => 'orders-bao-env', 'key' => 'DB_PASS'], $env['DB_PASS']['valueFrom']['secretKeyRef']);
        $this->assertStringNotContainsString('"pw"', json_encode($manifests->all()));
    }

    public function test_a_deploy_gives_the_project_its_policy_and_checks_its_values(): void
    {
        Http::fake(fn () => Http::response(['kind' => 'Status'], 200));
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'pw'])->assertCreated();
        $bao = $this->openBao($this->region);
        $bao->kv[$this->path()]['REMOVED'] = 'stale';
        $version = Version::create(['project_id' => $this->project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $id = strtolower($this->project->id);

        $deployment = Deployment::create(['project_id' => $this->project->id, 'version_id' => $version->id, 'status' => 'pending']);
        app(KnativeDeployService::class)->deploy($this->project->fresh(), $version, $deployment);

        $this->assertSame('active', $deployment->fresh()->status);
        // Read its own values, nothing else
        $this->assertSame("path \"runr/data/projects/{$id}\" {\n  capabilities = [\"read\"]\n}\n", $bao->policies["runr-project-{$id}"]);
        $role = $bao->paths["auth/kubernetes/role/runr-project-{$id}"];
        $this->assertSame(["runr-bao-{$id}"], $role['bound_service_account_names']);
        $this->assertSame(['fn'], $role['bound_service_account_namespaces']);
        // The key of a removed variable goes
        $this->assertSame(['DB_PASS' => 'pw'], $bao->kv[$this->path()]);

        // A value missing in OpenBao fails the next deploy with its name
        unset($bao->kv[$this->path()]['DB_PASS']);
        $failed = Deployment::create(['project_id' => $this->project->id, 'version_id' => $version->id, 'status' => 'pending']);
        app(KnativeDeployService::class)->deploy($this->project->fresh(), $version, $failed);
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertStringContainsString('DB_PASS', (string) $failed->fresh()->knative_message);
    }

    public function test_deleting_the_project_removes_its_values_and_access_from_openbao(): void
    {
        Http::fake(fn () => Http::response(['kind' => 'Status'], 200));
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'pw'])->assertCreated();
        $id = strtolower($this->project->id);

        app(KnativeDeployService::class)->teardownProject($this->project->fresh(), withSecretValues: true);

        $bao = $this->openBao($this->region);
        $this->assertArrayNotHasKey($this->path(), $bao->kv);
        $this->assertContains(['delete', "auth/kubernetes/role/runr-project-{$id}"], $bao->log);
        $this->assertContains(['delete-policy', "runr-project-{$id}"], $bao->log);
    }

    public function test_moving_the_project_takes_its_values_to_the_new_regions_place(): void
    {
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'pw'])->assertCreated();
        $plain = $this->regionWithCluster('eu', 'https://k8s-eu.test');

        $this->signedIn($this->member)->putJson("/api/projects/{$this->project->id}", ['knative_region_id' => $plain->id])->assertOk();

        // No OpenBao there: the region's Kubernetes Secret, and the variable follows
        $this->assertSame(['DB_PASS' => 'pw'], $this->regionSecrets->values($plain, 'orders-secret-env'));
        $this->assertSame('region', ProjectSecret::sole()->source);
    }

    public function test_the_database_move_goes_into_openbao_when_the_region_has_it(): void
    {
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'LEGACY', 'value' => 'old']);

        $this->artisan('secrets:move-to-region')->assertSuccessful();

        $this->assertSame(['LEGACY' => 'old'], $this->openBao($this->region)->kv[$this->path()]);
        $row = ProjectSecret::sole();
        $this->assertSame('openbao', $row->source);
        $this->assertNull($row->getRawOriginal('value'));
    }
}
