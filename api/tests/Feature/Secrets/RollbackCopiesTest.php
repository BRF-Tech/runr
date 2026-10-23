<?php

namespace Tests\Feature\Secrets;

use App\Models\AuditEvent;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\SecretStore;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrgTransfer\Secrets\ProjectSecretValues;
use App\Services\Secrets\OpenBaoSecretBackend;
use App\Services\Secrets\RegionSecretBackend;
use App\Services\Secrets\RollbackCopies;
use App\Services\Secrets\SecretFingerprint;
use App\Services\Secrets\SecretScope;
use App\Support\Secrets\SecretCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesOpenBao;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * The downgrade guard of pass-through (RollbackCopies, docs/upgrade.md "Rolling back"): RunR 0.5.10
 * builds a project's Secret from project_secrets.value on every deploy. Until the upgrade is
 * finalized every value keeps an encrypted copy there — what 0.5.10 deploys after a rollback is
 * the value, never an empty string — and references wait; `secrets:move-to-region --finalize`
 * checks the regions and drops the copies.
 */
class RollbackCopiesTest extends TestCase
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
        Http::fake(fn () => Http::response(['kind' => 'Status'], 200));

        $this->region = $this->regionWithCluster('fn');
        $this->project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        $this->project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        $this->member = $this->userWithRole('developer');

        // An installation upgraded from 0.5.10, not finalized yet
        Setting::query()->whereKey(RollbackCopies::SETTING)->delete();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/projects/{$this->project->id}/secrets{$suffix}";
    }

    /**
     * What RunR 0.5.10 writes into <slug>-secret-env on a deploy: (string) of every row's
     * decrypted value (KnativeSecretEnvTrait::secretEnvValues() of 0.5.10).
     *
     * @return array<string, string>
     */
    private function what0510Deploys(): array
    {
        return DB::table('project_secrets')->where('project_id', $this->project->id)->orderBy('name')->get()
            ->mapWithKeys(fn ($row) => [$row->name => (string) SecretCipher::decode($row->value)])->all();
    }

    private function inRegion(): ?array
    {
        return $this->regionSecrets->values($this->region, 'orders-secret-env');
    }

    public function test_a_fresh_installation_starts_finalized_and_an_upgraded_one_keeps_copies(): void
    {
        $migration = require database_path('migrations/2026_10_22_500001_add_rollback_copy_to_project_secrets.php');

        // Every migration in one batch: a fresh installation, nothing to roll back to
        $migration->up();
        $this->assertFalse(RollbackCopies::kept());

        // The pass-through migration ran in a later batch than the ones before it: an upgrade
        Setting::query()->whereKey(RollbackCopies::SETTING)->delete();
        DB::table('migrations')->where('migration', '2026_10_21_200001_project_secrets_pass_through')->update(['batch' => 2]);
        $migration->up();
        $this->assertTrue(RollbackCopies::kept());
    }

    public function test_a_value_set_before_finalizing_keeps_a_copy_that_0510_deploys(): void
    {
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'pw-1'])
            ->assertCreated()
            ->assertJsonPath('data.stored_in', 'region')
            ->assertJsonPath('data.rollback_copy', true)
            ->assertDontSee('pw-1');

        $this->assertSame(['DB_PASS' => 'pw-1'], $this->inRegion());
        $raw = DB::table('project_secrets')->value('value');
        $this->assertNotNull($raw);
        $this->assertTrue(SecretCipher::isEncrypted($raw), 'the copy is encrypted at rest');
        $this->assertSame(['DB_PASS' => 'pw-1'], $this->what0510Deploys());

        // RunR itself reads the region, not the copy
        $this->regionSecrets->seed($this->region, 'orders-secret-env', ['DB_PASS' => 'changed-in-the-cluster']);
        $this->signedIn($this->userWithRole('owner'))->getJson($this->url('/DB_PASS/value'))
            ->assertOk()->assertJsonPath('value', 'changed-in-the-cluster')->assertJsonPath('matches_fingerprint', false);

        // A new value replaces the copy with it
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'pw-2'])->assertOk();
        $this->assertSame(['DB_PASS' => 'pw-2'], $this->what0510Deploys());
    }

    public function test_the_move_keeps_the_value_as_a_copy_until_the_upgrade_is_finalized(): void
    {
        // Written by 0.5.10: in the database only
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'LEGACY', 'value' => 'old']);
        $this->assertSame('database', ProjectSecret::sole()->toPublicArray()['stored_in']);

        $this->artisan('secrets:move-to-region')
            ->expectsOutputToContain('Moved 1 value(s) of 1 project(s)')
            ->expectsOutputToContain('1 value(s) keep an encrypted copy in the database')
            ->doesntExpectOutputToContain('old')
            ->assertSuccessful();

        $this->assertSame(['LEGACY' => 'old'], $this->inRegion());
        $row = ProjectSecret::sole();
        $this->assertTrue($row->hasRollbackCopy());
        $this->assertFalse($row->isHeldInDatabase());
        $this->assertSame('region', $row->toPublicArray()['stored_in']);
        $this->assertSame(['LEGACY' => 'old'], $this->what0510Deploys(), 'a rollback deploys the value, not an empty string');

        // Nothing left to move: a second run writes nothing
        $writes = count($this->regionSecrets->calls);
        $this->artisan('secrets:move-to-region')->expectsOutputToContain('Moved 0 value(s) of 0 project(s)')->assertSuccessful();
        $this->assertCount($writes, $this->regionSecrets->calls);
    }

    public function test_finalizing_checks_every_value_in_its_region_and_drops_the_copies(): void
    {
        $store = SecretStore::create(['name' => 'vault', 'provider' => 'vault', 'auth' => 'kubernetes', 'settings' => ['server' => 'https://vault.test', 'role' => 'runr']]);
        $this->signedIn($this->member)->putJson($this->url('/API_KEY'), ['value' => 'key-1'])->assertCreated();
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'LEGACY', 'value' => 'old']);
        $this->artisan('secrets:move-to-region')->assertSuccessful();
        // The cluster lost one value since: the copy brings it back before it goes
        $this->regionSecrets->seed($this->region, 'orders-secret-env', ['API_KEY' => 'key-1']);

        $this->artisan('secrets:move-to-region', ['--finalize' => true, '--dry-run' => true])
            ->expectsOutputToContain('Would drop 2 copy(ies)')->assertSuccessful();
        $this->assertTrue(RollbackCopies::kept());

        $this->artisan('secrets:move-to-region', ['--finalize' => true])
            ->expectsOutputToContain('Dropped 2 copy(ies)')
            ->expectsOutputToContain('Finalized')
            ->assertSuccessful();

        $this->assertSame(['API_KEY' => 'key-1', 'LEGACY' => 'old'], $this->inRegion());
        $this->assertSame(0, DB::table('project_secrets')->whereNotNull('value')->count());
        $this->assertSame(0, DB::table('project_secrets')->where('rollback_copy', true)->count());
        $this->assertFalse(RollbackCopies::kept());
        $this->assertTrue(AuditEvent::where('action', 'secrets.pass_through_finalized')->exists());

        // From now on no value reaches the database, and references can be set
        $this->signedIn($this->member)->putJson($this->url('/NEW'), ['value' => 'n'])->assertCreated()->assertJsonPath('data.rollback_copy', false);
        $this->assertNull(ProjectSecret::where('name', 'NEW')->sole()->getRawOriginal('value'));
        $this->signedIn($this->member)->putJson($this->url('/DB_URL'), ['reference' => ['store_id' => $store->id, 'path' => 'apps/orders']])->assertCreated();
    }

    public function test_finalizing_waits_for_a_region_that_does_not_answer(): void
    {
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'pw'])->assertCreated();
        $this->regionSecrets->unreachable = true;

        $this->artisan('secrets:move-to-region', ['--finalize' => true])
            ->expectsOutputToContain('Not finalized')
            ->assertFailed();
        $this->assertTrue(RollbackCopies::kept());
        $this->assertSame(['DB_PASS' => 'pw'], $this->what0510Deploys());

        $this->regionSecrets->unreachable = false;
        $this->artisan('secrets:move-to-region', ['--finalize' => true])->assertSuccessful();
        $this->assertFalse(RollbackCopies::kept());
        $this->assertNull(DB::table('project_secrets')->value('value'));
    }

    public function test_finalizing_covers_the_whole_installation(): void
    {
        $this->artisan('secrets:move-to-region', ['--finalize' => true, '--project' => $this->project->id])->assertExitCode(2);
        $this->assertTrue(RollbackCopies::kept());
    }

    public function test_references_wait_for_the_finalized_upgrade(): void
    {
        $store = SecretStore::create(['name' => 'vault', 'provider' => 'vault', 'auth' => 'kubernetes', 'settings' => ['server' => 'https://vault.test', 'role' => 'runr']]);

        $this->signedIn($this->member)
            ->putJson($this->url('/DB_URL'), ['reference' => ['store_id' => $store->id, 'path' => 'apps/orders']])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'upgrade_not_finalized');
        $this->assertSame(0, ProjectSecret::count());

        // An organization import lists them as a manual step instead
        $manual = app(ProjectSecretValues::class)->writeReferences($this->project, ['DB_URL' => ['store' => 'vault', 'path' => 'apps/orders']], null);
        $this->assertSame(['DB_URL'], $manual);
    }

    public function test_a_copy_0510_changed_after_a_rollback_is_the_newer_value(): void
    {
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'before-rollback'])->assertCreated();
        // RunR 0.5.10 after a rollback: it writes the value, never the fingerprint
        DB::table('project_secrets')->update(['value' => Crypt::encryptString('set-on-0510')]);

        $row = ProjectSecret::sole();
        $this->assertTrue($row->isHeldInDatabase());
        $this->assertSame('database', $row->toPublicArray()['stored_in']);

        // A deploy writes it into the region
        app(RegionSecretBackend::class)->prepareDeploy(SecretScope::forProject($this->project->fresh()), collect([$row]), []);
        $this->assertSame(['DB_PASS' => 'set-on-0510'], $this->inRegion());

        // The move counts it as a new version and keeps it as the copy
        $this->artisan('secrets:move-to-region')->assertSuccessful();
        $row = ProjectSecret::sole();
        $this->assertTrue($row->hasRollbackCopy());
        $this->assertSame(2, $row->version);
        $this->assertTrue(SecretFingerprint::matches('set-on-0510', $row->fingerprint));
    }

    public function test_values_in_openbao_keep_a_copy_and_a_value_0510_changed_reaches_openbao_at_deploy(): void
    {
        $this->openBaoReady($this->region);
        $path = 'projects/'.strtolower($this->project->id);

        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'in-bao'])
            ->assertCreated()->assertJsonPath('data.stored_in', 'openbao')->assertJsonPath('data.rollback_copy', true);
        $this->assertSame(['DB_PASS' => 'in-bao'], $this->openBao($this->region)->kv[$path]);
        $this->assertSame(['DB_PASS' => 'in-bao'], $this->what0510Deploys());

        DB::table('project_secrets')->update(['value' => Crypt::encryptString('set-on-0510')]);
        $row = ProjectSecret::sole();
        $this->assertSame('set-on-0510', app(OpenBaoSecretBackend::class)->read(SecretScope::forProject($this->project->fresh()), $row));

        app(OpenBaoSecretBackend::class)->prepareDeploy(SecretScope::forProject($this->project->fresh()), collect([$row]), []);
        $this->assertSame(['DB_PASS' => 'set-on-0510'], $this->openBao($this->region)->kv[$path]);
    }
}
