<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\FaasConfig;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\SecretStore;
use App\Models\User;
use App\Models\Version;
use App\Services\Secrets\SecretFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * Secret env vars API (docs/security.md): pass-through values — written straight into the region,
 * never into RunR's database —, naming rules, the plain/secret name split, references to a secret
 * store, and reading a value back from the region only with secrets.view_values.
 */
class ProjectSecretApiTest extends TestCase
{
    use ActsWithRoles, FakesRegionSecrets, RefreshDatabase;

    private Project $project;

    private User $member;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRegionSecrets();
        $region = $this->regionWithCluster();

        $this->project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        $this->project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $this->project->id, 'handler' => 'main.handler', 'env_vars' => ['PLAIN' => 'visible']]);
        // the region it was given after its creation (an observer may have read the relation before)
        $this->project = $this->project->fresh();

        // Owner: every permission (secrets.view_values too). Developer: env.view + env.edit only.
        $this->owner = $this->userWithRole('owner');
        $this->member = $this->userWithRole('developer');
    }

    private function url(string $suffix = ''): string
    {
        return "/api/projects/{$this->project->id}/secrets{$suffix}";
    }

    private function inRegion(): ?array
    {
        return $this->regionSecrets->values($this->project->knativeRegion, "{$this->project->slug}-secret-env");
    }

    public function test_a_value_goes_into_the_region_and_never_into_the_database(): void
    {
        $this->signedIn($this->member)
            ->putJson($this->url('/API_TOKEN'), ['value' => 'tok-do-not-leak'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'API_TOKEN')
            ->assertJsonPath('data.source', 'region')
            ->assertJsonPath('data.stored_in', 'region')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('requires_deploy', true)
            ->assertJsonMissingPath('data.value')
            ->assertDontSee('tok-do-not-leak');

        // The region holds it; the database holds metadata and a keyed fingerprint
        $this->assertSame(['API_TOKEN' => 'tok-do-not-leak'], $this->inRegion());
        $row = DB::table('project_secrets')->first();
        $this->assertNull($row->value);
        $this->assertStringStartsWith('h1:', $row->fingerprint);
        $this->assertNotSame(hash('sha256', 'tok-do-not-leak'), substr($row->fingerprint, 3), 'keyed, not a plain hash');
        $this->assertTrue(SecretFingerprint::matches('tok-do-not-leak', $row->fingerprint));
        $this->assertStringNotContainsString('tok-do-not-leak', json_encode(DB::table('project_secrets')->get()));

        $this->signedIn($this->member)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.0.name', 'API_TOKEN')
            ->assertJsonPath('data.0.updated_by.name', $this->member->name)
            ->assertJsonPath('data.0.fingerprint', substr($row->fingerprint, 3, 12))
            ->assertJsonPath('can_view_values', false)
            ->assertJsonPath('storage', 'region')
            ->assertHeader('Cache-Control')
            ->assertDontSee('tok-do-not-leak');

        // The project resource never carries secret values either
        $this->signedIn($this->member)->getJson("/api/projects/{$this->project->id}")->assertDontSee('tok-do-not-leak');
    }

    public function test_a_value_is_replaced_with_a_new_version(): void
    {
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'one'])->assertCreated();
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'two'])->assertOk()->assertJsonPath('data.version', 2);

        $this->assertSame(1, ProjectSecret::count());
        $this->assertSame(['DB_PASS' => 'two'], $this->inRegion());
        $this->assertTrue(SecretFingerprint::matches('two', ProjectSecret::first()->fingerprint));
    }

    public function test_a_removed_value_leaves_the_region_at_once_when_nothing_runs(): void
    {
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'one'])->assertCreated();
        $this->signedIn($this->member)->putJson($this->url('/OTHER'), ['value' => 'two'])->assertCreated();

        $this->signedIn($this->member)->deleteJson($this->url('/DB_PASS'))->assertOk()->assertJsonPath('requires_deploy', true);

        $this->assertSame(['OTHER'], ProjectSecret::pluck('name')->all());
        $this->assertSame(['OTHER' => 'two'], $this->inRegion());

        $this->signedIn($this->member)->deleteJson($this->url('/DB_PASS'))->assertNotFound();
    }

    public function test_a_removed_value_stays_for_the_running_workload_until_the_next_deploy(): void
    {
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'one'])->assertCreated();
        $version = Version::create(['project_id' => $this->project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        Deployment::create(['project_id' => $this->project->id, 'version_id' => $version->id, 'status' => 'active']);

        $this->signedIn($this->member)->deleteJson($this->url('/DB_PASS'))->assertOk();

        // A pod the running revision starts now still finds it; the next deploy drops it
        $this->assertSame(0, ProjectSecret::count());
        $this->assertSame(['DB_PASS' => 'one'], $this->inRegion());
    }

    public function test_a_region_that_does_not_answer_changes_nothing(): void
    {
        $this->regionSecrets->unreachable = true;

        $this->signedIn($this->member)
            ->putJson($this->url('/API_TOKEN'), ['value' => 'tok'])
            ->assertStatus(503)
            ->assertJsonPath('reason', 'region_unreachable')
            ->assertDontSee('tok"');

        $this->assertSame(0, ProjectSecret::count());
    }

    public function test_a_project_without_a_region_cluster_cannot_hold_values(): void
    {
        $orphan = Project::create(['name' => 'no-region', 'service_type' => 'faas']);
        FaasConfig::create(['project_id' => $orphan->id, 'handler' => 'main.handler']);

        $this->signedIn($this->member)
            ->putJson("/api/projects/{$orphan->id}/secrets/KEY", ['value' => 'v'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('value');

        $this->assertSame(0, ProjectSecret::count());
    }

    public function test_names_follow_the_env_var_rules_and_stay_apart_from_plain_vars(): void
    {
        foreach (['1BAD', 'BAD-NAME', 'PORT', 'K_SERVICE', 'RUNR_MODE', 'PLAIN'] as $name) {
            $this->signedIn($this->member)
                ->putJson($this->url('/'.$name), ['value' => 'x'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('name');
        }

        $this->signedIn($this->member)
            ->putJson($this->url('/EMPTY'), ['value' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('value');

        $this->signedIn($this->member)
            ->putJson($this->url('/BIG'), ['value' => str_repeat('a', ProjectSecret::MAX_VALUE_BYTES + 1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('value');

        $this->assertSame(0, ProjectSecret::count());
        $this->assertNull($this->inRegion());
    }

    public function test_a_plain_env_var_cannot_take_the_name_of_a_secret(): void
    {
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'API_TOKEN']);

        $this->signedIn($this->member)
            ->putJson("/api/projects/{$this->project->id}", ['config' => ['env_vars' => ['API_TOKEN' => 'plain']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('config.env_vars');
    }

    public function test_the_secret_count_is_limited(): void
    {
        for ($i = 0; $i < ProjectSecret::MAX_PER_PROJECT; $i++) {
            ProjectSecret::create(['project_id' => $this->project->id, 'name' => "S{$i}"]);
        }

        $this->signedIn($this->member)
            ->putJson($this->url('/ONE_MORE'), ['value' => 'v'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        // Replacing an existing one still works
        $this->signedIn($this->member)->putJson($this->url('/S0'), ['value' => 'new'])->assertOk();
    }

    public function test_reading_a_value_back_reads_the_region_and_takes_secrets_view_values(): void
    {
        $this->signedIn($this->member)->putJson($this->url('/API_TOKEN'), ['value' => 'tok-123'])->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->signedIn($this->member)
            ->getJson($this->url('/API_TOKEN/value'))
            ->assertForbidden()
            ->assertDontSee('tok-123');

        $denied = AuditEvent::where('action', 'project.secret.reveal')->first();
        $this->assertSame('denied', $denied->result);

        $this->app['auth']->forgetGuards();

        $response = $this->signedIn($this->owner)
            ->getJson($this->url('/API_TOKEN/value'))
            ->assertOk()
            ->assertJsonPath('value', 'tok-123')
            ->assertJsonPath('matches_fingerprint', true);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertContains("read https://k8s.test op/{$this->project->slug}-secret-env", $this->regionSecrets->calls);

        $this->assertSame(1, AuditEvent::where('action', 'project.secret.reveal')->where('result', 'success')->count());
        $this->assertStringNotContainsString('tok-123', json_encode(AuditEvent::all()->map->toPublicArray()));

        $this->signedIn($this->owner)->getJson($this->url())->assertJsonPath('can_view_values', true);
    }

    public function test_reading_back_tells_a_value_changed_in_the_cluster_a_missing_one_and_a_dead_cluster(): void
    {
        $this->signedIn($this->owner)->putJson($this->url('/API_TOKEN'), ['value' => 'tok-123'])->assertCreated();

        // Changed in the cluster by hand: returned, but it is not the value RunR wrote
        $this->regionSecrets->seed($this->project->knativeRegion, "{$this->project->slug}-secret-env", ['API_TOKEN' => 'edited']);
        $this->signedIn($this->owner)->getJson($this->url('/API_TOKEN/value'))
            ->assertOk()->assertJsonPath('matches_fingerprint', false);

        // Gone from the cluster
        $this->regionSecrets->seed($this->project->knativeRegion, "{$this->project->slug}-secret-env", []);
        $this->signedIn($this->owner)->getJson($this->url('/API_TOKEN/value'))
            ->assertStatus(409)->assertJsonPath('reason', 'value_missing');

        $this->regionSecrets->unreachable = true;
        $this->signedIn($this->owner)->getJson($this->url('/API_TOKEN/value'))
            ->assertStatus(503)->assertJsonPath('reason', 'region_unreachable');
    }

    public function test_a_value_written_before_pass_through_is_read_from_the_database_until_moved(): void
    {
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'LEGACY', 'value' => 'old-value']);

        $this->signedIn($this->owner)->getJson($this->url())->assertJsonPath('data.0.stored_in', 'database');
        $this->signedIn($this->owner)->getJson($this->url('/LEGACY/value'))->assertOk()->assertJsonPath('value', 'old-value');
        $this->assertSame([], $this->regionSecrets->calls, 'not read from the region');

        // Set again: it moves into the region, the database copy goes
        $this->signedIn($this->owner)->putJson($this->url('/LEGACY'), ['value' => 'new-value'])->assertOk()->assertJsonPath('data.stored_in', 'region');
        $this->assertNull(DB::table('project_secrets')->where('name', 'LEGACY')->value('value'));
        $this->assertSame(['LEGACY' => 'new-value'], $this->inRegion());
    }

    public function test_with_database_storage_values_stay_in_the_database_as_before(): void
    {
        config(['secrets.storage' => 'database']);

        $this->signedIn($this->member)->putJson($this->url('/API_TOKEN'), ['value' => 'tok'])->assertCreated()
            ->assertJsonPath('data.stored_in', 'database');

        $this->assertNull($this->inRegion());
        $this->assertSame('tok', ProjectSecret::first()->value);
        $this->assertTrue(SecretFingerprint::matches('tok', ProjectSecret::first()->fingerprint));
    }

    public function test_a_secret_can_be_a_reference_to_a_secret_store(): void
    {
        $store = SecretStore::create(['name' => 'vault', 'provider' => 'vault', 'auth' => 'kubernetes', 'settings' => ['server' => 'https://vault.test', 'role' => 'runr']]);

        $this->signedIn($this->member)
            ->putJson($this->url('/DB_URL'), ['reference' => ['store_id' => $store->id, 'path' => 'apps/orders', 'key' => 'url']])
            ->assertCreated()
            ->assertJsonPath('data.source', 'external')
            ->assertJsonPath('data.reference.store_name', 'vault')
            ->assertJsonPath('data.reference.path', 'apps/orders')
            ->assertJsonPath('data.reference.key', 'url')
            ->assertJsonPath('data.sync.status', 'pending')
            ->assertJsonPath('requires_deploy', true);

        $this->assertNull($this->inRegion(), 'RunR writes nothing for a reference');
        $this->assertNull(DB::table('project_secrets')->value('value'));

        // Reading a reference back is the store's business
        $this->signedIn($this->owner)->getJson($this->url('/DB_URL/value'))->assertUnprocessable()->assertJsonPath('reason', 'unsupported');

        // A value and a reference at once, or an unknown store: refused
        $this->signedIn($this->member)
            ->putJson($this->url('/X'), ['value' => 'v', 'reference' => ['store_id' => $store->id, 'path' => 'p']])
            ->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->signedIn($this->member)
            ->putJson($this->url('/X'), ['reference' => ['store_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'path' => 'p']])
            ->assertUnprocessable()->assertJsonValidationErrors('reference.store_id');
    }

    public function test_a_value_replaces_a_reference_and_the_other_way_round(): void
    {
        $store = SecretStore::create(['name' => 'aws', 'provider' => 'aws', 'auth' => 'workload_identity', 'settings' => ['region' => 'eu-central-1', 'role_arn' => 'arn:aws:iam::123456789012:role/runr']]);

        $this->signedIn($this->member)->putJson($this->url('/TOKEN'), ['value' => 'v1'])->assertCreated();
        $this->signedIn($this->member)->putJson($this->url('/TOKEN'), ['reference' => ['store_id' => $store->id, 'path' => 'orders/token']])
            ->assertOk()->assertJsonPath('data.source', 'external')->assertJsonPath('data.version', 2);
        $this->assertNull(ProjectSecret::first()->fingerprint);

        $this->signedIn($this->member)->putJson($this->url('/TOKEN'), ['value' => 'v2'])
            ->assertOk()->assertJsonPath('data.source', 'region')->assertJsonMissingPath('data.reference');
        $this->assertNull(ProjectSecret::first()->secret_store_id);
        $this->assertSame(['TOKEN' => 'v2'], $this->inRegion());
    }

    public function test_former_ai_project_types_take_no_secrets(): void
    {
        $legacy = Project::create(['name' => 'agent', 'service_type' => 'aaas']);

        $this->signedIn($this->member)
            ->putJson("/api/projects/{$legacy->id}/secrets/KEY", ['value' => 'v'])
            ->assertUnprocessable();
    }

    public function test_secrets_go_with_the_project(): void
    {
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'API_TOKEN']);

        $this->project->delete();

        $this->assertSame(0, ProjectSecret::count());
    }
}
