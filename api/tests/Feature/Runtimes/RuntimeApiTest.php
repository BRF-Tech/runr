<?php

namespace Tests\Feature\Runtimes;

use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * /api/runtimes: the catalog, manifest validation with JSON-pointer problems, registration of
 * platform and custom runtimes, updates, deletion, starter templates — and the two permissions
 * (runtimes.manage, runtimes.use_custom; docs/permissions.md).
 */
class RuntimeApiTest extends TestCase
{
    use RefreshDatabase, RegistersRuntimes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
        // No region of these tests has a kubeconfig; nothing may reach a cluster
        Http::preventStrayRequests();
    }

    private function user(bool $admin = false): User
    {
        $user = User::factory()->create();
        // users.is_admin belongs to the Tenancy module; the gate reads the attribute
        $user->setAttribute('is_admin', $admin);

        return $user;
    }

    /** Multi-tenant central context: managing runtimes is for platform admins only */
    private function multiTenant(): void
    {
        config(['tenancy.enabled' => true]);
    }

    /**
     * An organization member without runtimes.manage (built-in role Developer, which holds
     * runtimes.use_custom): the first user of an install is its Owner, later users Developers.
     */
    private function member(): User
    {
        if (! User::query()->exists()) {
            $this->user();
        }

        return $this->user();
    }

    // ── Gates ───────────────────────────────────────────────────────────────

    public function test_single_tenant_owners_manage_runtimes(): void
    {
        $owner = $this->user(); // the first user of an install is its Owner

        $this->assertTrue(Gate::forUser($owner)->allows('runtimes.manage'));
        $this->assertTrue(Gate::forUser($owner)->allows('runtimes.use_custom'));
    }

    public function test_multi_tenant_gates(): void
    {
        $this->multiTenant();
        $admin = $this->user(true);
        $outsider = $this->user();

        $this->assertTrue(Gate::forUser($admin)->allows('runtimes.manage'));
        // No active tenant: nobody but platform admins has permissions
        $this->assertFalse(Gate::forUser($outsider)->allows('runtimes.manage'));
        Setting::set('custom_runtimes_enabled', 'true');
        $this->assertFalse(Gate::forUser($outsider)->allows('runtimes.use_custom'));
    }

    public function test_members_use_custom_runtimes_when_the_platform_allows_them(): void
    {
        $member = $this->member();

        $this->assertFalse(Gate::forUser($member)->allows('runtimes.manage'));
        $this->assertFalse(Gate::forUser($member)->allows('runtimes.use_custom'));

        Setting::set('custom_runtimes_enabled', 'true');
        $this->assertTrue(Gate::forUser($member)->allows('runtimes.use_custom'));
        $this->assertFalse(Gate::forUser($member)->allows('runtimes.manage'));
    }

    // ── Catalog ─────────────────────────────────────────────────────────────

    public function test_the_catalog_lists_the_built_ins_with_abilities(): void
    {
        $this->multiTenant();
        $response = $this->actingAs($this->user(), 'sanctum')->getJson('/api/runtimes')->assertOk();

        $this->assertSame(['python', 'nodejs', 'go', 'php', 'tensorflow', 'pytorch'], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('abilities', ['manage' => false, 'use_custom' => false])
            ->assertJsonPath('custom_enabled', false)
            ->assertJsonPath('data.0.language', 'python')
            ->assertJsonPath('data.0.source', 'builtin')
            ->assertJsonPath('data.0.run_mode', 'http')
            ->assertJsonPath('data.0.handler.filename', 'main.py')
            ->assertJsonPath('data.0.dependency_file', 'requirements.txt')
            ->assertJsonPath('data.0.versions.0.version', '3.14')
            ->assertJsonPath('data.0.versions.0.is_default', true)
            ->assertJsonPath('data.2.compiled', true);
    }

    public function test_disabled_and_region_limited_runtimes(): void
    {
        $a = KnativeRegion::create(['name' => 'a', 'is_active' => true]);
        $b = KnativeRegion::create(['name' => 'b', 'is_active' => true]);
        $this->registerRuntime($this->bashManifest(), [], Runtime::SOURCE_PLATFORM, [$a->id]);
        Runtime::whereKey('php')->update(['is_enabled' => false]);
        $user = $this->user();

        $ids = fn (string $query) => array_column($this->actingAs($user, 'sanctum')->getJson("/api/runtimes{$query}")->assertOk()->json('data'), 'id');

        $this->assertNotContains('php', $ids(''));
        $this->assertContains('bash', $ids(''));
        $this->assertContains('bash', $ids("?region={$a->id}"));
        $this->assertNotContains('bash', $ids("?region={$b->id}"));
        // managers see disabled runtimes with ?all=1
        $this->assertContains('php', $ids('?all=1'));
    }

    public function test_show_returns_the_manifest_and_files(): void
    {
        $this->registerRuntime($this->qManifest(), $this->qFiles());

        $this->actingAs($this->user(), 'sanctum')->getJson('/api/runtimes/q')->assertOk()
            ->assertJsonPath('data.manifest.run.mode', 'persistent')
            ->assertJsonPath('data.manifest.run.workers', 2)
            ->assertJsonPath('data.versions.0.built', true)
            ->assertJsonPath('data.versions.0.image', null);

        $data = $this->actingAs($this->user(), 'sanctum')->getJson('/api/runtimes/q')->json('data');
        $this->assertSame(array_keys($this->qFiles()), array_keys($data['files']));
        $this->assertStringContainsString('mode: persistent', $data['manifest_source']);

        // built-ins: the release manifest rendered as YAML
        $python = $this->actingAs($this->user(), 'sanctum')->getJson('/api/runtimes/python')->assertOk()->json('data');
        $this->assertStringContainsString('repository: runrsh/exec-python', $python['manifest_source']);
    }

    public function test_template_endpoint(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->getJson('/api/runtimes/python/template?service_type=faas&handler=app.run')->assertOk()
            ->assertJsonPath('files', ['app.py' => "def run(event, context):\n    return {\"statusCode\": 200, \"body\": \"Hello, World!\"}", 'requirements.txt' => "redis>=5.0\n"]);

        $go = $this->actingAs($user, 'sanctum')->getJson('/api/runtimes/go/template')->assertOk()->json('files');
        $this->assertSame(['main.go'], array_keys($go));
        $this->assertStringContainsString('func Handler(', $go['main.go']);
    }

    // ── Validation ──────────────────────────────────────────────────────────

    public function test_validate_reports_problems_with_json_pointer_paths(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->postJson('/api/runtimes/validate', ['manifest' => $this->bashManifest()])
            ->assertOk()->assertJsonPath('valid', true)->assertJsonPath('manifest.run.input', 'raw');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/runtimes/validate', ['manifest' => $this->bashManifest(['run' => ['mode' => 'cgi'], 'extensions' => ['.sh']])])
            ->assertStatus(422);

        $paths = array_column($response->json('problems'), 'path');
        $this->assertContains('/run/mode', $paths);
        $this->assertContains('/extensions/0', $paths);
        $this->assertNotEmpty($response->json('errors.manifest'));

        // dockerfile versions are checked against the files sent with the manifest
        $this->actingAs($user, 'sanctum')->postJson('/api/runtimes/validate', ['manifest' => $this->qManifest()])
            ->assertStatus(422)->assertJsonPath('problems.0.path', '/versions/0/dockerfile');
        $this->actingAs($user, 'sanctum')->postJson('/api/runtimes/validate', ['manifest' => $this->qManifest(), 'files' => $this->qFiles()])
            ->assertOk();
    }

    public function test_validate_needs_an_ability(): void
    {
        $this->multiTenant();

        $this->actingAs($this->user(), 'sanctum')->postJson('/api/runtimes/validate', ['manifest' => $this->bashManifest()])->assertForbidden();
    }

    // ── Registration ────────────────────────────────────────────────────────

    public function test_a_platform_admin_registers_a_runtime_with_its_versions(): void
    {
        $this->multiTenant();
        $admin = $this->user(true);

        $this->actingAs($admin, 'sanctum')->postJson('/api/runtimes', ['manifest' => $this->cManifest()])
            ->assertCreated()
            ->assertJsonPath('data.id', 'c')
            ->assertJsonPath('data.source', 'platform')
            ->assertJsonPath('data.compiled', true)
            ->assertJsonPath('data.run_mode', 'exec')
            ->assertJsonPath('data.versions.0.version', '17')
            ->assertJsonPath('data.versions.0.image', 'ghcr.io/brf-tech/reqs/alpine:3.21');

        $runtime = Runtime::findOrFail('c');
        $this->assertSame((string) $admin->id, $runtime->created_by);
        $this->assertNull($runtime->tenant_id);
        $this->assertSame('exec', $runtime->manifest['run']['mode']);
        $this->assertStringContainsString('gcc -O2', $runtime->manifest_source);

        $version = ExecutorImage::where('runtime', 'c')->sole();
        $this->assertSame(['17', 'ghcr.io/brf-tech/reqs/alpine:3.21', true, true], [$version->version, $version->docker_image, $version->is_default, $version->is_active]);

        // the id is taken now, and built-in ids are taken from the start
        $this->actingAs($admin, 'sanctum')->postJson('/api/runtimes', ['manifest' => $this->cManifest()])->assertStatus(422)->assertJsonValidationErrors('manifest');
        $this->actingAs($admin, 'sanctum')->postJson('/api/runtimes', ['manifest' => $this->bashManifest(['id' => 'python'])])->assertStatus(422)->assertJsonValidationErrors('manifest');
    }

    public function test_invalid_manifests_and_files_are_rejected_on_store(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->postJson('/api/runtimes', ['manifest' => "kind: Runtime\n"])
            ->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['manifest'], 'problems']);

        $this->actingAs($user, 'sanctum')->postJson('/api/runtimes', ['manifest' => $this->qManifest(), 'files' => ['../Dockerfile' => 'FROM x']])
            ->assertStatus(422)->assertJsonValidationErrors('files');

        $this->actingAs($user, 'sanctum')->postJson('/api/runtimes', ['manifest' => $this->qManifest(), 'files' => ['Dockerfile' => str_repeat('x', 1024 * 1024)]])
            ->assertStatus(422)->assertJsonValidationErrors('files');

        $this->assertSame(0, Runtime::where('source', '!=', 'builtin')->count());
    }

    public function test_an_organization_registers_a_custom_runtime_when_the_platform_allows_it(): void
    {
        $member = $this->member();

        $this->actingAs($member, 'sanctum')->postJson('/api/runtimes', ['manifest' => $this->bashManifest()])->assertForbidden();

        Setting::set('custom_runtimes_enabled', 'true');
        $this->actingAs($member, 'sanctum')->postJson('/api/runtimes', ['manifest' => $this->bashManifest()])
            ->assertCreated()->assertJsonPath('data.source', 'custom');

        // a custom runtime can not pick its own RuntimeClass (that is a platform decision)
        $this->actingAs($member, 'sanctum')->postJson('/api/runtimes', ['manifest' => $this->cManifest(['runtimeClass' => 'wasmtime'])])
            ->assertStatus(422)->assertJsonPath('problems.0.path', '/runtimeClass');
    }

    public function test_the_custom_runtimes_setting_is_for_runtime_managers(): void
    {
        $this->multiTenant();

        $this->actingAs($this->user(), 'sanctum')->patchJson('/api/settings', ['custom_runtimes_enabled' => true])->assertForbidden();
        $this->assertSame('false', Setting::get('custom_runtimes_enabled', 'false'));

        $this->actingAs($this->user(true), 'sanctum')->patchJson('/api/settings', ['custom_runtimes_enabled' => true])
            ->assertOk()->assertJsonPath('custom_runtimes_enabled', true);
        $this->assertSame('true', Setting::get('custom_runtimes_enabled'));
    }

    // ── Changes ─────────────────────────────────────────────────────────────

    public function test_update_manifest_versions_state_and_immutable_id(): void
    {
        $user = $this->user();
        $this->registerRuntime($this->bashManifest());

        $two = $this->bashManifest(['versions' => [['version' => '5.2', 'image' => 'docker.io/library/bash:5.2', 'default' => false], ['version' => '5.3', 'image' => 'docker.io/library/bash:5.3', 'default' => true]]]);
        $this->actingAs($user, 'sanctum')->putJson('/api/runtimes/bash', ['manifest' => $two])->assertOk()
            ->assertJsonPath('data.versions.1.version', '5.3')
            ->assertJsonPath('data.versions.1.is_default', true);

        // a version dropped from the manifest is retired, not deleted
        $this->actingAs($user, 'sanctum')->putJson('/api/runtimes/bash', ['manifest' => $this->bashManifest(['versions' => [['version' => '5.3', 'image' => 'docker.io/library/bash:5.3']]])])->assertOk();
        $this->assertFalse(ExecutorImage::where('runtime', 'bash')->where('version', '5.2')->sole()->is_active);

        $this->actingAs($user, 'sanctum')->putJson('/api/runtimes/bash', ['manifest' => $this->bashManifest(['id' => 'shell'])])->assertStatus(422)->assertJsonValidationErrors('manifest');

        $this->actingAs($user, 'sanctum')->putJson('/api/runtimes/bash', ['is_enabled' => false, 'regions' => []])->assertOk()
            ->assertJsonPath('data.is_enabled', false)->assertJsonPath('data.regions', []);
    }

    public function test_built_ins_only_change_their_availability(): void
    {
        $user = $this->user();
        $region = KnativeRegion::create(['name' => 'eu', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')->putJson('/api/runtimes/python', ['manifest' => $this->bashManifest(['id' => 'python'])])
            ->assertStatus(422)->assertJsonValidationErrors('manifest');

        $this->actingAs($user, 'sanctum')->putJson('/api/runtimes/php', ['is_enabled' => false, 'regions' => [$region->id]])->assertOk()
            ->assertJsonPath('data.is_enabled', false)->assertJsonPath('data.regions', [$region->id]);
        $this->assertNull(Runtime::findOrFail('php')->manifest);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/runtimes/php')->assertForbidden();
    }

    public function test_delete_refuses_a_runtime_projects_use(): void
    {
        $user = $this->user();
        $this->registerRuntime($this->bashManifest());
        Project::create(['name' => 'uses-bash', 'service_type' => 'xaas', 'runtime' => 'bash']);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/runtimes/bash')->assertStatus(409);

        Project::where('runtime', 'bash')->delete();
        $this->actingAs($user, 'sanctum')->deleteJson('/api/runtimes/bash')->assertNoContent();
        $this->assertNull(Runtime::find('bash'));
        $this->assertSame(0, ExecutorImage::where('runtime', 'bash')->count());
    }

    public function test_members_can_not_change_platform_runtimes(): void
    {
        Setting::set('custom_runtimes_enabled', 'true');
        $this->registerRuntime($this->bashManifest());
        $member = $this->member();

        $this->actingAs($member, 'sanctum')->putJson('/api/runtimes/bash', ['is_enabled' => false])->assertForbidden();
        $this->actingAs($member, 'sanctum')->deleteJson('/api/runtimes/bash')->assertForbidden();

        // their own custom runtime they can change
        $this->registerRuntime($this->cManifest(), [], Runtime::SOURCE_CUSTOM);
        $this->actingAs($member, 'sanctum')->putJson('/api/runtimes/c', ['is_enabled' => false])->assertOk();
    }
}
