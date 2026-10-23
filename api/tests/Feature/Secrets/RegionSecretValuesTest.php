<?php

namespace Tests\Feature\Secrets;

use App\Models\EnvironmentSecret;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectSecret;
use App\Services\RegionMigrate\RegionSecretValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesOpenBao;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * A region move takes the projects' secret values along (docs/security.md): RunR holds no copy,
 * so they are copied from the cluster the region leaves to the new one — and back by a rollback,
 * values set meanwhile included, and the own values of the environments that run there. Only the
 * variables' own keys, in memory only.
 */
class RegionSecretValuesTest extends TestCase
{
    use FakesOpenBao, FakesRegionSecrets, RefreshDatabase;

    private const OLD = 'https://old.k8s.test';

    private const NEW = 'https://new.k8s.test';

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRegionSecrets();
        $this->region = $this->regionWithCluster('fn', self::OLD);
    }

    private function project(string $name, array $names): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'faas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        foreach ($names as $variable) {
            ProjectSecret::create(['project_id' => $project->id, 'name' => $variable]);
        }

        return $project;
    }

    private function on(string $server): KnativeRegion
    {
        return (clone $this->region)->forceFill(['kubeconfig' => self::kubeconfigFor($server)]);
    }

    public function test_the_values_follow_the_move_and_come_back_with_a_rollback(): void
    {
        $this->project('orders', ['DB_PASS', 'API_KEY']);
        $this->project('empty', []);
        // On the cluster the region leaves: the values, and a RunR key the deploy writes again
        $this->regionSecrets->seed($this->on(self::OLD), 'orders-secret-env', ['DB_PASS' => 'pw', 'API_KEY' => 'k', 'RUNR_MCP_GATEWAY_KEY' => 'runr-own']);

        $result = app(RegionSecretValues::class)->copy($this->region, self::kubeconfigFor(self::OLD), self::kubeconfigFor(self::NEW));

        $this->assertSame(['orders'], $result['copied']);
        $this->assertSame([], $result['failed']);
        $this->assertSame(['DB_PASS' => 'pw', 'API_KEY' => 'k'], $this->regionSecrets->values($this->on(self::NEW), 'orders-secret-env'));
        $this->assertNull($this->regionSecrets->values($this->on(self::NEW), 'empty-secret-env'));

        // Changed while the region runs on the new cluster, then rolled back
        $this->regionSecrets->seed($this->on(self::NEW), 'orders-secret-env', ['DB_PASS' => 'pw-2', 'API_KEY' => 'k']);
        app(RegionSecretValues::class)->copy($this->region, self::kubeconfigFor(self::NEW), self::kubeconfigFor(self::OLD));

        $this->assertSame('pw-2', $this->regionSecrets->values($this->on(self::OLD), 'orders-secret-env')['DB_PASS']);
    }

    public function test_references_and_values_still_in_the_database_need_no_copy(): void
    {
        $project = $this->project('orders', []);
        ProjectSecret::create(['project_id' => $project->id, 'name' => 'LEGACY', 'value' => 'in-db']);
        ProjectSecret::create(['project_id' => $project->id, 'name' => 'REF', 'source' => 'external', 'remote_path' => 'p']);

        $result = app(RegionSecretValues::class)->copy($this->region, self::kubeconfigFor(self::OLD), self::kubeconfigFor(self::NEW));

        $this->assertSame(['copied' => [], 'failed' => []], $result);
        $this->assertSame([], $this->regionSecrets->calls);
    }

    public function test_what_cannot_be_copied_is_named_for_the_deploy_to_report(): void
    {
        $this->project('orders', ['DB_PASS', 'GONE']);
        $this->project('billing', ['TOKEN']);
        $this->regionSecrets->seed($this->on(self::OLD), 'orders-secret-env', ['DB_PASS' => 'pw']);

        $result = app(RegionSecretValues::class)->copy($this->region, self::kubeconfigFor(self::OLD), self::kubeconfigFor(self::NEW));

        $this->assertSame([], $result['copied']);
        $this->assertCount(2, $result['failed']);
        $this->assertStringContainsString('orders: not on the cluster it leaves: GONE', $result['failed'][0]);
        $this->assertStringContainsString('billing', $result['failed'][1]);
        // What was there is copied all the same
        $this->assertSame(['DB_PASS' => 'pw'], $this->regionSecrets->values($this->on(self::NEW), 'orders-secret-env'));
        $this->assertStringNotContainsString('pw', json_encode($result));
    }

    public function test_values_in_the_regions_openbao_go_to_the_new_clusters_openbao(): void
    {
        $this->fakeOpenBao();
        $this->openBaoReady($this->region);
        $project = $this->project('orders', []);
        ProjectSecret::create(['project_id' => $project->id, 'name' => 'IN_BAO', 'source' => 'openbao']);
        $path = 'projects/'.strtolower($project->id);
        $this->openBao($this->on(self::OLD))->kv[$path] = ['IN_BAO' => 'from-old-bao', 'OTHER_KEY' => 'not-a-variable'];

        $result = app(RegionSecretValues::class)->copy($this->region, self::kubeconfigFor(self::OLD), self::kubeconfigFor(self::NEW));

        $this->assertSame(['orders'], $result['copied']);
        $this->assertSame(['IN_BAO' => 'from-old-bao'], $this->openBao($this->on(self::NEW))->kv[$path]);
    }

    public function test_the_own_values_of_the_environments_in_the_region_follow_the_move(): void
    {
        $project = $this->project('orders', []);
        $staging = ProjectEnvironment::query()->create([
            'project_id' => $project->id, 'name' => 'staging', 'is_primary' => false, 'workload_name' => 'orders-staging', 'position' => 1,
        ]);
        EnvironmentSecret::query()->create(['project_id' => $project->id, 'environment_id' => $staging->id, 'name' => 'API_KEY']);
        // its own value, and an inherited copy the next deploy writes again
        $this->regionSecrets->seed($this->on(self::OLD), 'orders-staging-secret-env', ['API_KEY' => 'staging-key', 'INHERITED' => 'copy']);

        $result = app(RegionSecretValues::class)->copy($this->region, self::kubeconfigFor(self::OLD), self::kubeconfigFor(self::NEW));

        $this->assertSame(['orders-staging'], $result['copied']);
        $this->assertSame(['API_KEY' => 'staging-key'], $this->regionSecrets->values($this->on(self::NEW), 'orders-staging-secret-env'));
    }

    public function test_a_cluster_that_does_not_answer_copies_nothing(): void
    {
        $this->project('orders', ['DB_PASS']);
        $this->regionSecrets->unreachable = true;

        $result = app(RegionSecretValues::class)->copy($this->region, self::kubeconfigFor(self::OLD), self::kubeconfigFor(self::NEW));

        $this->assertSame([], $result['copied']);
        $this->assertStringContainsString('orders', $result['failed'][0]);
    }
}
