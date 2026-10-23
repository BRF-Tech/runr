<?php

namespace Tests\Feature\Secrets;

use App\Models\AuditEvent;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Services\Secrets\RegionSecrets;
use App\Services\Secrets\SecretFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakeRegionSecrets;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * The pass-through upgrade (docs/upgrade.md): `php artisan secrets:move-to-region` pushes the
 * values RunR's database still holds into their regions, reads them back, compares them by keyed
 * fingerprint and only then clears them from the database. Idempotent, resumable, never prints a
 * value.
 */
class MoveSecretsToRegionCommandTest extends TestCase
{
    use FakesRegionSecrets, RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRegionSecrets();
        $this->region = $this->regionWithCluster('fn');
    }

    /** @param array<string, string> $values written the way RunR did before pass-through */
    private function project(string $name, array $values, ?KnativeRegion $region = null): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'faas']);
        if ($region !== null) {
            $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        }
        foreach ($values as $variable => $value) {
            ProjectSecret::create(['project_id' => $project->id, 'name' => $variable, 'value' => $value]);
        }

        return $project;
    }

    public function test_values_move_into_their_regions_and_leave_the_database(): void
    {
        $orders = $this->project('orders', ['DB_PASS' => 'pw-orders-1', 'API_KEY' => 'key-orders-1'], $this->region);
        $billing = $this->project('billing', ['TOKEN' => 'tok-billing-1'], $this->region);

        $this->artisan('secrets:move-to-region')
            ->expectsOutputToContain('Moved 3 value(s) of 2 project(s)')
            ->doesntExpectOutputToContain('pw-orders-1')
            ->assertSuccessful();

        $this->assertSame(['DB_PASS' => 'pw-orders-1', 'API_KEY' => 'key-orders-1'], $this->regionSecrets->values($this->region, 'orders-secret-env'));
        $this->assertSame(['TOKEN' => 'tok-billing-1'], $this->regionSecrets->values($this->region, 'billing-secret-env'));

        $this->assertSame(0, DB::table('project_secrets')->whereNotNull('value')->count());
        $row = ProjectSecret::where('project_id', $orders->id)->where('name', 'DB_PASS')->sole();
        $this->assertTrue(SecretFingerprint::matches('pw-orders-1', $row->fingerprint));
        $this->assertSame('region', $row->toPublicArray()['stored_in']);
        $this->assertNotNull($billing->secrets()->sole()->fingerprint);

        $event = AuditEvent::where('action', 'secrets.moved_to_region')->sole();
        $this->assertSame('success', $event->result);
        $this->assertStringNotContainsString('pw-orders-1', json_encode($event->getAttributes()));
    }

    public function test_a_second_run_moves_nothing(): void
    {
        $this->project('orders', ['DB_PASS' => 'pw'], $this->region);

        $this->artisan('secrets:move-to-region')->assertSuccessful();
        $writes = count(array_filter($this->regionSecrets->calls, fn ($c) => str_starts_with($c, 'write ')));

        $this->artisan('secrets:move-to-region')->expectsOutputToContain('Moved 0 value(s) of 0 project(s)')->assertSuccessful();
        $this->assertSame($writes, count(array_filter($this->regionSecrets->calls, fn ($c) => str_starts_with($c, 'write '))));
    }

    public function test_a_value_already_in_the_region_is_verified_not_written_again(): void
    {
        $this->project('orders', ['DB_PASS' => 'pw'], $this->region);
        $this->regionSecrets->seed($this->region, 'orders-secret-env', ['DB_PASS' => 'pw']);

        $this->artisan('secrets:move-to-region')->expectsOutputToContain('1 already there')->assertSuccessful();

        $this->assertSame([], array_values(array_filter($this->regionSecrets->calls, fn ($c) => str_starts_with($c, 'write '))));
        $this->assertNull(DB::table('project_secrets')->value('value'));
    }

    public function test_a_region_that_does_not_answer_keeps_the_values_for_the_next_run(): void
    {
        $this->project('orders', ['DB_PASS' => 'pw'], $this->region);
        $this->regionSecrets->unreachable = true;

        $this->artisan('secrets:move-to-region')
            ->expectsOutputToContain('still in the database')
            ->assertFailed();
        $this->assertSame('pw', ProjectSecret::sole()->value);

        // Resumed once the region answers
        $this->regionSecrets->unreachable = false;
        $this->artisan('secrets:move-to-region')->assertSuccessful();
        $this->assertNull(DB::table('project_secrets')->value('value'));
    }

    public function test_a_value_the_region_does_not_keep_is_not_wiped(): void
    {
        $this->project('orders', ['DB_PASS' => 'pw'], $this->region);
        // A region that loses what it is given (admission, a sync from elsewhere): the read-back differs
        $fake = new class extends FakeRegionSecrets
        {
            public function writeTo(string $kubeconfig, string $namespace, string $name, array $values, array $remove = [], array $labels = [], array $annotations = [], ?string $region = null): void
            {
                parent::writeTo($kubeconfig, $namespace, $name, array_map(fn () => 'something-else', $values), $remove, $labels, $annotations, $region);
            }
        };
        $this->app->instance(RegionSecrets::class, $fake);

        $this->artisan('secrets:move-to-region')->assertFailed();

        $this->assertSame('pw', ProjectSecret::sole()->value);
    }

    public function test_a_dry_run_changes_nothing_and_a_project_without_a_cluster_is_reported(): void
    {
        $this->project('orders', ['DB_PASS' => 'pw'], $this->region);
        $this->project('homeless', ['X' => 'y']);

        $this->artisan('secrets:move-to-region', ['--dry-run' => true])
            ->expectsOutputToContain('Would move 1 value(s)')
            ->expectsOutputToContain('homeless: no region cluster')
            ->assertSuccessful();

        $this->assertSame([], $this->regionSecrets->calls);
        $this->assertSame(2, DB::table('project_secrets')->whereNotNull('value')->count());
        $this->assertFalse(AuditEvent::where('action', 'secrets.moved_to_region')->exists());
    }

    public function test_one_project_can_be_moved_alone(): void
    {
        $orders = $this->project('orders', ['DB_PASS' => 'pw'], $this->region);
        $this->project('billing', ['TOKEN' => 't'], $this->region);

        $this->artisan('secrets:move-to-region', ['--project' => $orders->id])->assertSuccessful();

        $this->assertNull(ProjectSecret::where('project_id', $orders->id)->sole()->getRawOriginal('value'));
        $this->assertSame(1, DB::table('project_secrets')->whereNotNull('value')->count());
    }
}
