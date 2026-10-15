<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Hosting\McpTemplates;
use App\Mcp\Models\HostedServer;
use App\Mcp\Models\HostedServerCall;
use App\Models\AuditEvent;
use App\Models\Version;
use App\Services\KnativeDeployService;
use App\Support\Secrets\SecretCipher;
use App\Support\Secrets\SecretColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Mcp\Tools\McpToolFixtures;
use Tests\TestCase;

/**
 * Hosted MCP servers in the console API (docs/mcp.md, "Hosting MCP servers"): templates, serving
 * a project, access mode and path, key rotation, the call log, permissions, audit, the project
 * resource's badge field and the gateway key in the deployed workload.
 */
class HostedServerApiTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        $this->region->forceFill(['knative_settings' => ['knative_domain' => 'op.fn.test']])->save();
    }

    private function url($project, string $suffix = ''): string
    {
        return "/api/projects/{$project->id}/mcp-server{$suffix}";
    }

    public function test_the_templates_are_the_bundled_starters(): void
    {
        $data = $this->signedIn($this->owner)->getJson('/api/mcp-servers/templates')->assertOk()->json('data');

        $this->assertSame(['python', 'node', 'image'], array_column($data, 'id'));
        $python = $data[0];
        $this->assertSame('mcp-python', $python['runtime']);
        $this->assertSame('faas', $python['service_type']);
        $this->assertFalse($python['runtime_registered']);
        $this->assertFalse($python['runtime_available']);
        $this->assertTrue($python['can_register_runtime'], 'the owner holds runtimes.manage');
        $this->assertArrayHasKey('server.py', $python['files']);
        $this->assertArrayHasKey('requirements.txt', $python['files']);
        $this->assertStringContainsString('FastMCP', $python['files']['server.py']);
        $this->assertSame('mcp-python', json_decode($python['runtime_manifest'], true)['id']);

        $this->assertSame('mcp-node', $data[1]['runtime']);
        $this->assertArrayHasKey('server.ts', $data[1]['files']);
        $this->assertArrayHasKey('package.json', $data[1]['files']);

        $this->assertSame('caas', $data[2]['service_type']);
        $this->assertSame('/mcp', $data[2]['path']);

        // A developer may register custom runtimes only while the platform allows them
        $developer = $this->userWithRole('developer');
        $this->assertFalse($this->signedIn($developer)->getJson('/api/mcp-servers/templates')->json('data.0.can_register_runtime'));
    }

    public function test_the_bundle_matches_the_example_sources(): void
    {
        if (! McpTemplates::sourceDir()) {
            $this->markTestSkipped('examples/mcp is not next to the API in this checkout');
        }

        $this->assertSame(0, Artisan::call('mcp:templates-bundle', ['--check' => true]), Artisan::output());
    }

    public function test_a_project_is_served_as_an_mcp_server(): void
    {
        $project = $this->containerProject('weather');

        $response = $this->signedIn($this->owner)->putJson($this->url($project), ['access_mode' => 'oauth'])
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('data.handle', 'weather')
            ->assertJsonPath('data.url', 'http://localhost/mcp/servers/weather')
            ->assertJsonPath('data.access_mode', 'oauth')
            ->assertJsonPath('data.path', '/mcp')
            ->assertJsonPath('data.function_url', 'https://weather.op.fn.test/mcp')
            ->assertJsonPath('data.resource_metadata', 'http://localhost/.well-known/oauth-protected-resource/mcp/servers/weather');
        $this->assertStringNotContainsString('rmg_', $response->getContent(), 'the gateway key is never shown');

        $server = HostedServer::sole();
        $this->assertStringStartsWith('rmg_', $server->signing_key);
        $this->assertTrue(SecretCipher::isEncrypted(DB::table('mcp_servers')->value('signing_key')), 'the key is encrypted at rest');
        $this->assertContains('signing_key', SecretColumns::forTable('mcp_servers'));

        $created = AuditEvent::where('action', 'mcp_server.create')->sole();
        $this->assertSame($project->id, $created->target_id);
        $this->assertSame('oauth', $created->metadata['access_mode']);
        $this->assertStringNotContainsString('rmg_', json_encode(AuditEvent::all()->toArray()));

        // The project resource names its server (the console's badge)
        $this->signedIn($this->owner)->getJson("/api/projects/{$project->id}")
            ->assertJsonPath('data.mcp_server', ['handle' => 'weather', 'url' => 'http://localhost/mcp/servers/weather', 'access_mode' => 'oauth']);
        $this->signedIn($this->owner)->getJson('/api/projects')->assertJsonPath('data.0.mcp_server.handle', 'weather');
    }

    public function test_the_settings_change_and_are_audited(): void
    {
        $project = $this->containerProject('weather');
        $this->signedIn($this->owner)->putJson($this->url($project), ['access_mode' => 'token'])->assertCreated();

        $this->signedIn($this->owner)->putJson($this->url($project), ['access_mode' => 'public', 'path' => '/sse/mcp', 'audit_calls' => true])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('data.access_mode', 'public')
            ->assertJsonPath('data.path', '/sse/mcp')
            ->assertJsonPath('data.audit_calls', true)
            ->assertJsonPath('data.resource_metadata', null);

        $this->assertTrue(AuditEvent::where('action', 'mcp_server.update')->exists());

        $this->signedIn($this->owner)->putJson($this->url($project), ['access_mode' => 'everyone'])->assertUnprocessable()->assertJsonValidationErrors('access_mode');
        $this->signedIn($this->owner)->putJson($this->url($project), ['path' => 'no-slash'])->assertUnprocessable()->assertJsonValidationErrors('path');
    }

    public function test_only_http_projects_can_be_served(): void
    {
        $project = $this->containerProject('cron');
        $project->containerConfig->forceFill(['trigger_type' => 'schedule', 'schedule' => '*/5 * * * *'])->save();

        $this->signedIn($this->owner)->putJson($this->url($project), ['access_mode' => 'token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('trigger_type');
        $this->assertSame(0, HostedServer::count());
    }

    public function test_permissions(): void
    {
        $project = $this->containerProject('weather');
        $viewer = $this->userWithRole('viewer');
        $developer = $this->userWithRole('developer');

        $this->signedIn($viewer)->putJson($this->url($project), ['access_mode' => 'token'])
            ->assertForbidden()->assertJsonPath('permissions', ['mcp.servers.manage']);
        $this->signedIn($developer)->putJson($this->url($project), ['access_mode' => 'token'])->assertCreated();

        $this->signedIn($viewer)->getJson($this->url($project))->assertOk();
        $this->signedIn($viewer)->postJson($this->url($project, '/rotate-key'))->assertForbidden();
        $this->signedIn($viewer)->deleteJson($this->url($project))->assertForbidden();

        // A scoped token carries its own limit
        $this->app['auth']->forgetGuards();
        $plain = $developer->createToken('read-only', ['projects.view'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$plain)->putJson($this->url($project), ['access_mode' => 'public'])->assertForbidden();
    }

    public function test_a_project_without_a_server_answers_404(): void
    {
        $project = $this->containerProject('weather');

        $this->signedIn($this->owner)->getJson($this->url($project))->assertNotFound();
        $this->signedIn($this->owner)->postJson($this->url($project, '/rotate-key'))->assertNotFound();
        $this->signedIn($this->owner)->getJson($this->url($project, '/calls'))->assertNotFound();
        $this->signedIn($this->owner)->getJson("/api/projects/{$project->id}")->assertJsonPath('data.mcp_server', null);
    }

    public function test_the_key_rotates_and_takes_effect_with_the_next_deploy(): void
    {
        $project = $this->containerProject('weather');
        $this->signedIn($this->owner)->putJson($this->url($project), ['access_mode' => 'token'])->assertCreated();
        $before = HostedServer::sole()->signing_key;
        $this->deployment($project, $this->version($project, 1), 'active');

        $this->travel(1)->minutes();
        $this->signedIn($this->owner)->postJson($this->url($project, '/rotate-key'))
            ->assertOk()
            ->assertJsonPath('data.needs_redeploy', true);

        $server = HostedServer::sole();
        $this->assertNotSame($before, $server->signing_key);
        $this->assertSame([$server->signing_key, $before], $server->signingKeys(), 'the previous key keeps signing');
        $this->assertTrue(AuditEvent::where('action', 'mcp_server.key.rotate')->exists());

        $this->travel(HostedServer::ROTATION_GRACE_DAYS + 1)->days();
        $this->assertSame([$server->fresh()->signing_key], $server->fresh()->signingKeys());
    }

    public function test_the_gateway_key_reaches_the_workload_through_the_secret(): void
    {
        $project = $this->containerProject('weather');
        $this->signedIn($this->owner)->putJson($this->url($project), ['access_mode' => 'token'])->assertCreated();
        $key = HostedServer::sole()->signing_key;
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        $manifests = collect(app(KnativeDeployService::class)->buildManifests($project->fresh(), $version));

        $secret = $manifests->firstWhere('kind', 'Secret');
        $this->assertSame("{$project->slug}-secret-env", $secret['metadata']['name']);
        $this->assertSame(base64_encode($key), $secret['data']['RUNR_MCP_GATEWAY_KEY']);

        $service = $manifests->firstWhere('kind', 'Service');
        $env = collect($service['spec']['template']['spec']['containers'][0]['env']);
        $this->assertSame(
            ['secretKeyRef' => ['name' => "{$project->slug}-secret-env", 'key' => 'RUNR_MCP_GATEWAY_KEY']],
            $env->firstWhere('name', 'RUNR_MCP_GATEWAY_KEY')['valueFrom'],
        );
        $this->assertStringNotContainsString($key, json_encode($service));

        // Not an MCP server: nothing of it in the workload
        $other = $this->containerProject('plain', []);
        $version = Version::create(['project_id' => $other->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $this->assertStringNotContainsString('RUNR_MCP_GATEWAY_KEY', json_encode(app(KnativeDeployService::class)->buildManifests($other->fresh(), $version)));
    }

    public function test_the_call_log_is_paginated_and_filtered(): void
    {
        $project = $this->containerProject('weather');
        $this->signedIn($this->owner)->putJson($this->url($project), ['access_mode' => 'public'])->assertCreated();
        $server = HostedServer::sole();
        foreach ([['tools/call', 'ok'], ['tools/call', 'error'], ['initialize', 'ok']] as $i => [$method, $outcome]) {
            HostedServerCall::create([
                'mcp_server_id' => $server->id, 'project_id' => $project->id, 'occurred_at' => now()->addSeconds($i),
                'method' => $method, 'name' => $method === 'tools/call' ? 'forecast' : null, 'status_code' => 200,
                'outcome' => $outcome, 'duration_ms' => 12, 'actor_type' => 'anonymous', 'auth' => 'public',
            ]);
        }

        $all = $this->signedIn($this->owner)->getJson($this->url($project, '/calls'))->assertOk();
        $this->assertSame(3, $all->json('meta.total'));
        $this->assertSame('initialize', $all->json('data.0.method'), 'newest first');
        $this->assertSame(['type' => 'anonymous', 'id' => null, 'name' => null], $all->json('data.0.actor'));

        $errors = $this->signedIn($this->owner)->getJson($this->url($project, '/calls').'?outcome=error')->json('data');
        $this->assertCount(1, $errors);
        $this->assertSame('forecast', $errors[0]['name']);

        // Reading the call log is reading logs
        $this->customRole('watcher', ['projects.view']);
        $this->signedIn($this->userWithRole('watcher'))->getJson($this->url($project, '/calls'))->assertForbidden();
    }

    public function test_the_organization_lists_its_servers(): void
    {
        $a = $this->containerProject('weather');
        $b = $this->containerProject('stocks');
        $this->signedIn($this->owner)->putJson($this->url($a), ['access_mode' => 'token'])->assertCreated();
        $this->signedIn($this->owner)->putJson($this->url($b), ['access_mode' => 'public'])->assertCreated();

        $this->assertSame(['stocks', 'weather'], array_column($this->signedIn($this->owner)->getJson('/api/mcp-servers')->json('data'), 'handle'));

        // Project access limits the list
        $this->customRole('outsider', ['regions.view']);
        $outsider = $this->userWithRole('outsider');
        $this->grantProject($outsider, $a, 'viewer');
        $this->assertSame(['weather'], array_column($this->signedIn($outsider)->getJson('/api/mcp-servers')->json('data'), 'handle'));
    }

    public function test_stopping_and_deleting_remove_the_server_and_its_calls(): void
    {
        $project = $this->containerProject('weather');
        $this->signedIn($this->owner)->putJson($this->url($project), ['access_mode' => 'public'])->assertCreated();
        HostedServerCall::create([
            'mcp_server_id' => HostedServer::sole()->id, 'project_id' => $project->id, 'occurred_at' => now(),
            'method' => 'ping', 'status_code' => 200, 'outcome' => 'ok', 'duration_ms' => 1, 'actor_type' => 'anonymous', 'auth' => 'public',
        ]);

        $this->signedIn($this->owner)->deleteJson($this->url($project))->assertNoContent();
        $this->assertSame(0, HostedServer::count());
        $this->assertSame(0, HostedServerCall::count());
        $this->assertTrue(AuditEvent::where('action', 'mcp_server.delete')->exists());

        $this->signedIn($this->owner)->putJson($this->url($project), ['access_mode' => 'public'])->assertCreated();
        $project->delete();
        $this->assertSame(0, HostedServer::count(), 'a deleted project stops being served');
    }
}
