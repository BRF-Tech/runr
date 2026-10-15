<?php

namespace Tests\Feature;

use App\Events\PlatformEvent;
use App\Jobs\InstallRedisJob;
use App\Jobs\InstallRegistryAndCreateRegionJob;
use App\Jobs\MigrateRegionJob;
use App\Models\Deployment;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\GitService;
use App\Services\ProjectService;
use App\Support\Secrets\SecretRedactor;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;
use Modules\AuthProvider\Providers\AuthProviderServiceProvider;
use Tests\TestCase;

/**
 * Where secrets never appear (docs/security.md): broadcasts, the git snapshot, queue payloads,
 * the shell session config, API responses of SSO providers.
 */
class SecretLeakPreventionTest extends TestCase
{
    use RefreshDatabase;

    public function test_broadcasts_mask_env_var_values_and_secret_fields(): void
    {
        $event = new PlatformEvent('project.updated', ['project' => [
            'id' => 'p1',
            'config' => [
                'env_vars' => ['API_URL' => 'https://api.test', 'TOKEN' => 'tok-secret'],
                'image_pull_secret' => 'regcred-name-is-secret-looking',
                'trigger_type' => 'http',
            ],
            'function_tls_secret_name' => 'tls-name',
            'has_client_secret' => true,
            'password' => 'p@ss',
            'token_ids' => [1, 2],
        ]]);

        $payload = $event->broadcastWith()['payload']['project'];

        $this->assertSame(['API_URL' => SecretRedactor::MASK, 'TOKEN' => SecretRedactor::MASK], $payload['config']['env_vars']);
        $this->assertTrue($payload['config']['env_vars_redacted']);
        $this->assertSame('http', $payload['config']['trigger_type']);
        $this->assertSame('tls-name', $payload['function_tls_secret_name'], 'a secret *name* stays readable');
        $this->assertTrue($payload['has_client_secret']);
        $this->assertSame(SecretRedactor::MASK, $payload['password']);
        $this->assertSame([1, 2], $payload['token_ids']);
        $this->assertStringNotContainsString('tok-secret', json_encode($payload));
    }

    public function test_the_git_snapshot_lists_env_var_names_only(): void
    {
        $written = [];
        $this->mock(GitService::class, function ($git) use (&$written) {
            $git->shouldReceive('writeFiles')->andReturnUsing(function ($project, array $files) use (&$written) {
                $written = $files;

                return 'commit-sha';
            });
        });

        $project = Project::create(['name' => 'svc', 'service_type' => 'faas']);
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'env_vars' => []]);

        $version = app(ProjectService::class)->updateFaaSConfig(
            $project->load('faasConfig'), ['env_vars' => ['DB_PASS' => 'snapshot-secret-value']], 'dev',
        );

        $this->assertArrayHasKey('.config.json', $written);
        $this->assertStringNotContainsString('snapshot-secret-value', $written['.config.json']);
        $this->assertSame(['DB_PASS'], json_decode($written['.config.json'], true)['env_vars']);

        // The encrypted DB snapshot keeps the value (deployment details, rollback display)
        $this->assertSame('snapshot-secret-value', Version::find($version->id)->config_snapshot['env_vars']['DB_PASS']);
    }

    public function test_jobs_with_credentials_are_encrypted_on_the_queue(): void
    {
        $region = new KnativeRegion(['name' => 'r']);

        $this->assertInstanceOf(ShouldBeEncrypted::class, new InstallRedisJob($region, 'external', 'redis:6379', 'pw'));
        $this->assertInstanceOf(ShouldBeEncrypted::class, new MigrateRegionJob($region, 'kubeconfig'));
        $this->assertInstanceOf(ShouldBeEncrypted::class, new InstallRegistryAndCreateRegionJob('t', ['kubeconfig' => 'k']));
    }

    public function test_the_shell_session_config_is_encrypted_and_carries_the_requester(): void
    {
        $kubeconfig = "apiVersion: v1\nkind: Config\ncurrent-context: c\nclusters: [{name: k, cluster: {server: 'https://k8s.test'}}]\ncontexts: [{name: c, context: {cluster: k, user: u}}]\nusers: [{name: u, user: {token: shell-kube-token}}]\n";
        $region = KnativeRegion::create(['name' => 'op', 'project_namespace' => 'op', 'kubeconfig' => $kubeconfig, 'is_active' => true]);
        $project = Project::create(['name' => 'svc', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active']);
        $user = User::factory()->create(['username' => 'jane']);

        $stored = null;
        Redis::shouldReceive('setex')->once()->andReturnUsing(function ($key, $ttl, $value) use (&$stored) {
            $stored = $value;

            return true;
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deployments/{$deployment->id}/exec")
            ->assertOk();

        $this->assertStringNotContainsString('shell-kube-token', $stored);
        $config = json_decode(Crypt::decryptString($stored), true);
        $this->assertStringContainsString('shell-kube-token', $config['kubeconfig']);
        $this->assertSame((string) $user->id, $config['audit']['actor_id']);
        $this->assertSame('deployment', $config['audit']['target']['type']);
        $this->assertNotEmpty($config['audit']['request_id']);
    }

    public function test_the_sso_client_secret_is_write_only(): void
    {
        config(['authprovider.enabled' => true]);
        $this->app->register(AuthProviderServiceProvider::class, true);
        Artisan::call('migrate', ['--path' => 'Modules/AuthProvider/Database/Migrations', '--force' => true]);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();

        $admin = User::factory()->create(['username' => 'admin']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/auth-providers', [
            'name' => 'GitHub',
            'driver' => 'github',
            'client_id' => 'client-id-1',
            'client_secret' => 'client-secret-abcd',
        ])
            ->assertCreated()
            ->assertJsonMissingPath('client_secret')
            ->assertJsonPath('has_client_secret', true)
            ->assertJsonPath('client_secret_hint', SecretRedactor::MASK.'abcd')
            ->assertDontSee('client-secret-abcd');

        $this->actingAs($admin, 'sanctum')->getJson('/api/auth-providers')
            ->assertOk()
            ->assertDontSee('client-secret-abcd');
    }
}
