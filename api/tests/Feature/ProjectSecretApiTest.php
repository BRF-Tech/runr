<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\FaasConfig;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\User;
use App\Support\Secrets\SecretCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Secret env vars API (docs/security.md): write-only values, naming rules, the plain/secret name
 * split, and reading a value back only with secrets.view_values.
 */
class ProjectSecretApiTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private Project $project;

    private User $member;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        FaasConfig::create(['project_id' => $this->project->id, 'handler' => 'main.handler', 'env_vars' => ['PLAIN' => 'visible']]);

        // Owner: every permission (secrets.view_values too). Developer: env.view + env.edit only.
        $this->owner = $this->userWithRole('owner');
        $this->member = $this->userWithRole('developer');
    }

    private function url(string $suffix = ''): string
    {
        return "/api/projects/{$this->project->id}/secrets{$suffix}";
    }

    public function test_a_secret_value_is_write_only(): void
    {
        $this->signedIn($this->member)
            ->putJson($this->url('/API_TOKEN'), ['value' => 'tok-do-not-leak'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'API_TOKEN')
            ->assertJsonPath('requires_deploy', true)
            ->assertJsonMissingPath('data.value')
            ->assertDontSee('tok-do-not-leak');

        $this->signedIn($this->member)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.0.name', 'API_TOKEN')
            ->assertJsonPath('data.0.updated_by.name', $this->member->name)
            ->assertJsonPath('can_view_values', false)
            ->assertHeader('Cache-Control')
            ->assertDontSee('tok-do-not-leak');

        $raw = DB::table('project_secrets')->value('value');
        $this->assertTrue(SecretCipher::isEncrypted($raw));
        $this->assertSame('tok-do-not-leak', ProjectSecret::first()->value);

        // The project resource never carries secret values either
        $this->signedIn($this->member)->getJson("/api/projects/{$this->project->id}")->assertDontSee('tok-do-not-leak');
    }

    public function test_a_secret_is_replaced_and_removed(): void
    {
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'one'])->assertCreated();
        $this->signedIn($this->member)->putJson($this->url('/DB_PASS'), ['value' => 'two'])->assertOk();

        $this->assertSame(1, ProjectSecret::count());
        $this->assertSame('two', ProjectSecret::first()->value);

        $this->signedIn($this->member)->deleteJson($this->url('/DB_PASS'))->assertOk();
        $this->assertSame(0, ProjectSecret::count());

        $this->signedIn($this->member)->deleteJson($this->url('/DB_PASS'))->assertNotFound();
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
    }

    public function test_a_plain_env_var_cannot_take_the_name_of_a_secret(): void
    {
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'API_TOKEN', 'value' => 's']);

        $this->signedIn($this->member)
            ->putJson("/api/projects/{$this->project->id}", ['config' => ['env_vars' => ['API_TOKEN' => 'plain']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('config.env_vars');
    }

    public function test_the_secret_count_is_limited(): void
    {
        for ($i = 0; $i < ProjectSecret::MAX_PER_PROJECT; $i++) {
            ProjectSecret::create(['project_id' => $this->project->id, 'name' => "S{$i}", 'value' => 'v']);
        }

        $this->signedIn($this->member)
            ->putJson($this->url('/ONE_MORE'), ['value' => 'v'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        // Replacing an existing one still works
        $this->signedIn($this->member)->putJson($this->url('/S0'), ['value' => 'new'])->assertOk();
    }

    public function test_reading_a_value_back_takes_secrets_view_values(): void
    {
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'API_TOKEN', 'value' => 'tok-123']);

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
            ->assertJsonPath('value', 'tok-123');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->assertSame(1, AuditEvent::where('action', 'project.secret.reveal')->where('result', 'success')->count());
        $this->assertStringNotContainsString('tok-123', json_encode(AuditEvent::all()->map->toPublicArray()));

        $this->signedIn($this->owner)->getJson($this->url())->assertJsonPath('can_view_values', true);
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
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'API_TOKEN', 'value' => 's']);

        $this->project->delete();

        $this->assertSame(0, ProjectSecret::count());
    }
}
