<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\User;
use App\Models\Version;
use App\Support\Secrets\SecretCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Secret variables through the project config (docs/security.md, "Secret environment variables"):
 * config.secret_env_keys names them (never values), config.secret_env_vars of POST /projects and
 * PUT /projects/{project} writes them — the form the CLI uses. An empty value removes one.
 */
class ProjectSecretEnvConfigTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private Project $project;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        FaasConfig::create(['project_id' => $this->project->id, 'handler' => 'main.handler', 'env_vars' => ['PLAIN' => 'visible']]);

        $this->owner = $this->userWithRole('owner');
        $this->member = $this->userWithRole('developer');
    }

    private function url(): string
    {
        return "/api/projects/{$this->project->id}";
    }

    public function test_the_config_names_the_secret_variables_never_their_values(): void
    {
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'API_TOKEN', 'value' => 'tok-do-not-leak']);
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'DB_PASS', 'value' => 'pw-do-not-leak']);

        $this->signedIn($this->member)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.config.secret_env_keys', ['API_TOKEN', 'DB_PASS'])
            ->assertJsonPath('data.config.env_vars', ['PLAIN' => 'visible'])
            ->assertDontSee('tok-do-not-leak')
            ->assertDontSee('pw-do-not-leak');

        $this->signedIn($this->member)->getJson('/api/projects')
            ->assertOk()
            ->assertJsonPath('data.0.config.secret_env_keys', ['API_TOKEN', 'DB_PASS'])
            ->assertDontSee('tok-do-not-leak');
    }

    public function test_a_project_without_secrets_sends_an_empty_list(): void
    {
        // The list is how clients detect write-only secret support
        $this->signedIn($this->member)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.config.secret_env_keys', []);
    }

    public function test_without_env_view_the_names_are_hidden_too(): void
    {
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'API_TOKEN', 'value' => 's']);
        $viewer = $this->userWithRole('viewer');

        $this->signedIn($viewer)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.config.env_vars_hidden', true)
            ->assertJsonPath('data.config.env_vars', null)
            ->assertJsonPath('data.config.secret_env_keys', null)
            ->assertDontSee('API_TOKEN');
    }

    public function test_an_update_stores_secret_env_vars_write_only(): void
    {
        $this->signedIn($this->member)->putJson($this->url(), [
            'config' => [
                'env_vars' => ['PLAIN' => 'visible'],
                'secret_env_vars' => ['API_TOKEN' => 'tok-do-not-leak'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.config.secret_env_keys', ['API_TOKEN'])
            ->assertJsonPath('data.config.env_vars', ['PLAIN' => 'visible'])
            ->assertDontSee('tok-do-not-leak');

        $secret = ProjectSecret::sole();
        $this->assertSame('API_TOKEN', $secret->name);
        $this->assertSame('tok-do-not-leak', $secret->value);
        $this->assertEquals($this->member->id, $secret->updated_by);
        $this->assertTrue(SecretCipher::isEncrypted(DB::table('project_secrets')->value('value')));

        // Never a plain variable, a version snapshot or an audit record
        $this->assertSame(['PLAIN' => 'visible'], $this->project->faasConfig()->first()->env_vars);
        foreach (Version::where('project_id', $this->project->id)->get() as $version) {
            $this->assertStringNotContainsString('tok-do-not-leak', json_encode($version->config_snapshot));
        }
        foreach (AuditEvent::all() as $event) {
            $this->assertStringNotContainsString('tok-do-not-leak', json_encode($event->getAttributes()));
        }
    }

    public function test_an_empty_value_removes_a_secret_variable(): void
    {
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'DB_PASS', 'value' => 'old']);
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'KEEP', 'value' => 'kept']);

        $this->signedIn($this->member)->putJson($this->url(), ['config' => ['secret_env_vars' => ['DB_PASS' => '', 'GONE_ALREADY' => '']]])
            ->assertOk()
            ->assertJsonPath('data.config.secret_env_keys', ['KEEP']);

        $this->assertSame(['KEEP'], ProjectSecret::pluck('name')->all());
    }

    public function test_a_variable_moves_between_plain_and_secret_in_one_request(): void
    {
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'API_TOKEN', 'value' => 's']);

        // Secret -> plain: the same request removes the secret
        $this->signedIn($this->member)->putJson($this->url(), [
            'config' => ['env_vars' => ['PLAIN' => 'visible', 'API_TOKEN' => 'now-plain'], 'secret_env_vars' => ['API_TOKEN' => '']],
        ])->assertOk();
        $this->assertSame(0, ProjectSecret::count());

        // Plain -> secret: the plain map of the same request no longer has it
        $this->signedIn($this->member)->putJson($this->url(), [
            'config' => ['env_vars' => ['API_TOKEN' => 'now-plain'], 'secret_env_vars' => ['PLAIN' => 'now-secret']],
        ])
            ->assertOk()
            ->assertJsonPath('data.config.secret_env_keys', ['PLAIN'])
            ->assertJsonPath('data.config.env_vars', ['API_TOKEN' => 'now-plain']);
    }

    public function test_a_plain_name_cannot_be_set_as_secret_while_it_stays_plain(): void
    {
        $this->signedIn($this->member)->putJson($this->url(), ['config' => ['secret_env_vars' => ['PLAIN' => 'x']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('config.secret_env_vars.PLAIN');

        $this->assertSame(0, ProjectSecret::count());
    }

    public function test_secret_names_and_values_are_validated(): void
    {
        $this->signedIn($this->member)->putJson($this->url(), ['config' => ['secret_env_vars' => [
            '1BAD' => 'x',
            'PORT' => 'x',
            'NUMBER' => 5,
            'BIG' => str_repeat('a', ProjectSecret::MAX_VALUE_BYTES + 1),
        ]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'config.secret_env_vars.1BAD',
                'config.secret_env_vars.PORT',
                'config.secret_env_vars.NUMBER',
                'config.secret_env_vars.BIG',
            ]);

        $this->assertSame(0, ProjectSecret::count());
    }

    public function test_the_secret_count_is_limited(): void
    {
        for ($i = 0; $i < ProjectSecret::MAX_PER_PROJECT; $i++) {
            ProjectSecret::create(['project_id' => $this->project->id, 'name' => "S{$i}", 'value' => 'v']);
        }

        $this->signedIn($this->member)->putJson($this->url(), ['config' => ['secret_env_vars' => ['ONE_MORE' => 'v']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('config.secret_env_vars');

        // Replacing one, or swapping one for another, stays within the limit
        $this->signedIn($this->member)->putJson($this->url(), ['config' => ['secret_env_vars' => ['S0' => '', 'ONE_MORE' => 'v']]])
            ->assertOk();
    }

    public function test_secret_env_vars_take_env_edit(): void
    {
        $viewer = $this->userWithRole('viewer');
        $this->signedIn($viewer)->putJson($this->url(), ['config' => ['secret_env_vars' => ['API_TOKEN' => 'x']]])
            ->assertForbidden();

        // env.edit alone is enough: secret variables are no other project field
        $this->customRole('env-editor', ['env.edit']);
        $editor = $this->userWithRole('env-editor');
        $this->assertAllowed($this->signedIn($editor)->putJson($this->url(), ['config' => ['secret_env_vars' => ['API_TOKEN' => 'x']]]));

        $this->assertSame(['API_TOKEN'], ProjectSecret::pluck('name')->all());
    }

    public function test_a_new_project_takes_secret_env_vars(): void
    {
        $region = KnativeRegion::create(['name' => 'op', 'is_active' => true, 'is_demo' => true]);

        $this->signedIn($this->member)->postJson('/api/projects', [
            'name' => 'with-secrets',
            'service_type' => 'caas',
            'knative_region_id' => $region->id,
            'config' => [
                'image' => 'nginx:1.27-alpine',
                'trigger_type' => 'http',
                'env_vars' => ['MODE' => 'prod'],
                'secret_env_vars' => ['API_TOKEN' => 'tok-do-not-leak'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('config.secret_env_keys', ['API_TOKEN'])
            ->assertDontSee('tok-do-not-leak');

        $project = Project::where('name', 'with-secrets')->sole();
        $this->assertSame('tok-do-not-leak', $project->secrets()->sole()->value);
        $this->assertSame(['MODE' => 'prod'], $project->containerConfig()->first()->env_vars);

        // Creating with secrets takes env.edit, like plain variables
        $this->customRole('maker', ['projects.create']);
        $this->signedIn($this->userWithRole('maker'))->postJson('/api/projects', [
            'name' => 'maker-secrets', 'service_type' => 'caas', 'knative_region_id' => $region->id,
            'config' => ['image' => 'nginx', 'secret_env_vars' => ['A' => '1']],
        ])->assertForbidden();
    }
}
