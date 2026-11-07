<?php

namespace Tests\Feature\Runtimes;

use App\Jobs\AnnounceRuntimePatchesJob;
use App\Jobs\DeleteRuntimeSourceSecretsJob;
use App\Models\Runtime;
use App\Models\RuntimeSource;
use App\Models\Setting;
use App\Models\User;
use App\Services\GitDeploy\GitRunner;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Runtimes\Sources\CatalogPayload;
use App\Services\Runtimes\Sources\GitSourceReader;
use App\Services\Runtimes\Sources\RuntimeSourceSync;
use App\Services\Runtimes\Sources\SourceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsRuntimeSources;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * The rules of runtime sources (docs/runtimes.md#runtime-ids, #private-sources, #runtime-sources):
 * one namespace of runtime ids and who wins it, credentials that never leave the encrypted column,
 * and the API with its two permissions.
 */
class RuntimeSourceRulesTest extends TestCase
{
    use BuildsRuntimeSources, RefreshDatabase, RegistersRuntimes;

    private const TOKEN = 'ghp_SuperSecretToken1234567890abcdef';

    private const PASSWORD = 'registry-Pa55w0rd-very-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
        $this->setUpRuntimeSources();
        Bus::fake([AnnounceRuntimePatchesJob::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownRuntimeSources();
        parent::tearDown();
    }

    private function publish(string $repository, array $manifests, array $patches = []): string
    {
        return $this->publishArtifact($repository, 'stable', CatalogPayload::MEDIA_TYPE, $this->catalog($manifests, $patches));
    }

    private function sync(RuntimeSource $source): RuntimeSource
    {
        $source = app(RuntimeSourceSync::class)->sync($source, true);
        app(RuntimeRegistry::class)->forget();

        return $source;
    }

    private function messages(RuntimeSource $source): array
    {
        return array_column((array) $source->problems, 'message');
    }

    // ── Runtime ids ─────────────────────────────────────────────────────────

    public function test_no_source_takes_an_id_of_the_built_in_catalog(): void
    {
        $this->publish('runtimes/catalog', [$this->luaManifest('python'), $this->luaManifest()]);

        $source = $this->sync($this->ociSource());

        $this->assertSame(RuntimeSource::PARTIAL, $source->status);
        $this->assertContains("The id 'python' is already provided by RunR's built-in catalog; this runtime is skipped.", $this->messages($source));
        $this->assertSame(Runtime::SOURCE_BUILTIN, Runtime::query()->findOrFail('python')->source);
        $this->assertNotNull(Runtime::query()->find('lua'));
    }

    public function test_the_first_registration_wins(): void
    {
        $this->publish('runtimes/catalog', [$this->luaManifest()]);
        $this->publishArtifact('other/catalog', 'stable', CatalogPayload::MEDIA_TYPE, $this->catalog([$this->luaManifest()]));
        $first = $this->sync($this->ociSource());
        $second = $this->sync($this->ociSource(['name' => 'other', 'url' => 'registry.other.test/other/catalog:stable']));

        $this->assertSame($first->id, Runtime::query()->findOrFail('lua')->source_id);
        $this->assertContains("The id 'lua' is already provided by the runtime source \"acme-runtimes\"; this runtime is skipped.", $this->messages($second));

        // a runtime registered by hand holds its id as well
        $this->registerRuntime($this->bashManifest());
        $this->publish('runtimes/catalog', [$this->luaManifest(), $this->luaManifest('bash')]);
        $this->assertContains("The id 'bash' is already provided by a platform runtime registered by hand; this runtime is skipped.", $this->messages($this->sync($first->fresh())));
    }

    public function test_an_organization_source_never_names_another_organization(): void
    {
        $this->publish('runtimes/catalog', [$this->luaManifest()]);
        $this->sync($this->ociSource(['scope' => RuntimeSource::SCOPE_ORGANIZATION, 'tenant_id' => 'org-a']));
        $this->assertSame(Runtime::SOURCE_CUSTOM, Runtime::query()->findOrFail('lua')->source);
        $this->assertSame('org-a', Runtime::query()->findOrFail('lua')->tenant_id);

        $theirs = $this->sync($this->ociSource(['name' => 'b', 'scope' => RuntimeSource::SCOPE_ORGANIZATION, 'tenant_id' => 'org-b']));

        $this->assertContains("The id 'lua' is already taken on this platform; this runtime is skipped. Pick another id.", $this->messages($theirs));
        $this->assertStringNotContainsString('org-a', json_encode($theirs->problems));
    }

    public function test_an_organization_source_can_not_set_a_runtime_class(): void
    {
        $this->publish('runtimes/catalog', [$this->luaManifest('lua', ['runtimeClass' => 'kata-fc'])]);

        $source = $this->sync($this->ociSource(['scope' => RuntimeSource::SCOPE_ORGANIZATION, 'tenant_id' => 'org-a']));

        $this->assertTrue(collect($source->problems)->contains(fn ($p) => $p['path'] === '/runtimeClass'));
        $this->assertNull(Runtime::query()->find('lua'));
    }

    public function test_an_orphaned_runtime_is_adopted_by_a_source_of_the_same_owner_only(): void
    {
        $this->publish('runtimes/catalog', [$this->luaManifest()]);
        $old = $this->sync($this->ociSource());
        $this->publish('runtimes/catalog', [$this->luaManifest('other')]);
        $this->sync($old->fresh());
        $this->assertNotNull(Runtime::query()->findOrFail('lua')->orphaned_at);

        // another organization can not take it
        $this->publishArtifact('org/catalog', 'stable', CatalogPayload::MEDIA_TYPE, $this->catalog([$this->luaManifest()]));
        $org = $this->sync($this->ociSource(['name' => 'org', 'url' => 'registry.org.test/org/catalog:stable', 'scope' => RuntimeSource::SCOPE_ORGANIZATION, 'tenant_id' => 'org-a']));
        $this->assertNotEmpty($org->problems);

        // another platform source adopts it: the projects follow
        $this->publishArtifact('new/catalog', 'stable', CatalogPayload::MEDIA_TYPE, $this->catalog([$this->luaManifest()]));
        $new = $this->sync($this->ociSource(['name' => 'new', 'url' => 'registry.new.test/new/catalog:stable']));
        $lua = Runtime::query()->findOrFail('lua');
        $this->assertSame($new->id, $lua->source_id);
        $this->assertNull($lua->orphaned_at);
    }

    public function test_an_id_twice_in_one_source_keeps_the_first(): void
    {
        $this->publish('runtimes/catalog', [$this->luaManifest(), $this->luaManifest('lua', ['name' => 'Lua again'])]);

        $source = $this->sync($this->ociSource());

        $this->assertSame('Lua', Runtime::query()->findOrFail('lua')->name);
        $this->assertTrue(collect($this->messages($source))->contains(fn ($m) => str_contains($m, 'is defined twice in this source')));
    }

    public function test_registration_by_hand_can_not_take_an_id_of_the_catalog(): void
    {
        $this->expectException(ValidationException::class);
        $this->registerRuntime($this->bashManifest(['id' => 'python']));
    }

    public function test_a_source_managed_runtime_changes_through_its_source_only(): void
    {
        $this->publish('runtimes/catalog', [$this->luaManifest()]);
        $this->sync($this->ociSource());
        $admin = User::factory()->create();

        $this->actingAs($admin, 'sanctum')->putJson('/api/runtimes/lua', ['manifest' => $this->yamlOf($this->luaManifest('lua', ['name' => 'Changed']))])
            ->assertStatus(422)->assertJsonPath('errors.manifest.0', 'The Lua runtime comes from the runtime source "acme-runtimes": change it there; here only its availability (enabled, regions) can change.');
        // one by one: enabled / disabled
        $this->actingAs($admin, 'sanctum')->putJson('/api/runtimes/lua', ['is_enabled' => false])->assertOk()->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.managed', true)->assertJsonPath('data.source_name', 'acme-runtimes');
        $this->assertNotNull(app(RuntimeRegistry::class)->unavailableReason('lua', null, 'http'));
        // deleted here it would come back with the next read
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/runtimes/lua')->assertStatus(422);
        Runtime::query()->whereKey('lua')->update(['orphaned_at' => now()]);
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/runtimes/lua')->assertNoContent();
    }

    // ── Credentials ─────────────────────────────────────────────────────────

    public function test_credentials_are_encrypted_write_only_and_masked(): void
    {
        $admin = User::factory()->create();
        Log::spy();
        $this->registryGate = fn (Request $r) => Http::response(['errors' => [['code' => 'DENIED', 'message' => 'token '.self::TOKEN.' and '.base64_encode('bot:'.self::PASSWORD).' refused']]], 403);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/runtime-sources', [
            'name' => 'private',
            'kind' => 'oci-catalog',
            'url' => 'registry.acme.test/private/catalog:stable',
            'registry_username' => 'bot',
            'registry_password' => self::PASSWORD,
        ])->assertCreated();

        $json = $response->getContent();
        $this->assertStringNotContainsString(self::PASSWORD, $json);
        $response->assertJsonPath('data.credentials.registry_username', 'bot')
            ->assertJsonPath('data.credentials.registry_password_set', true)
            ->assertJsonPath('data.status', 'auth_failed');

        $id = $response->json('data.id');
        $raw = (string) DB::table('runtime_sources')->where('id', $id)->value('credentials');
        $this->assertStringNotContainsString(self::PASSWORD, $raw, 'encrypted at rest');
        $this->assertSame(self::PASSWORD, RuntimeSource::query()->findOrFail($id)->credential('registry', 'password'));

        // the stored error and every listing: masked
        foreach (['/api/runtime-sources', "/api/runtime-sources/{$id}"] as $url) {
            $this->assertStringNotContainsString(self::PASSWORD, $this->actingAs($admin, 'sanctum')->getJson($url)->assertOk()->getContent());
        }
        $this->assertStringNotContainsString(self::PASSWORD, (string) RuntimeSource::query()->findOrFail($id)->error);
        Log::shouldNotHaveReceived('warning', fn ($message, $context = []) => str_contains(json_encode($context), self::PASSWORD));

        // omitted keeps, null removes
        $this->actingAs($admin, 'sanctum')->putJson("/api/runtime-sources/{$id}", ['interval_minutes' => 30])->assertOk()
            ->assertJsonPath('data.credentials.registry_password_set', true);
        $this->actingAs($admin, 'sanctum')->putJson("/api/runtime-sources/{$id}", ['registry_username' => null, 'registry_password' => null])->assertOk()
            ->assertJsonPath('data.credentials.registry_password_set', false);
    }

    /**
     * auth_failed is for a source whose own credentials were refused. A read without credentials
     * that the registry refuses (401 / 403) is not_found: missing or not public.
     */
    public function test_a_refused_read_is_auth_failed_only_with_credentials_for_that_registry(): void
    {
        $this->publish('runtimes/catalog', [$this->luaManifest()]);
        $this->registryGate = fn (Request $r) => str_contains($r->url(), '/v2/runtimes/catalog/') ? Http::response(['errors' => [['code' => 'UNAUTHORIZED']]], 401) : null;

        $anonymous = $this->sync($this->ociSource(['name' => 'anonymous']));
        $this->assertSame(RuntimeSource::NOT_FOUND, $anonymous->status);
        $this->assertStringContainsString('not public (HTTP 401); a private catalog needs registry credentials', (string) $anonymous->error);

        $withCredentials = $this->sync($this->ociSource(['name' => 'with-credentials',
            'credentials' => ['registry' => ['username' => 'bot', 'password' => self::PASSWORD]]]));
        $this->assertSame(RuntimeSource::AUTH_FAILED, $withCredentials->status);
        $this->assertStringContainsString('the registry refused the credentials (HTTP 401)', (string) $withCredentials->error);

        // Credentials for the runtime images' registry only: the catalog is read without them
        $otherRegistry = $this->sync($this->ociSource(['name' => 'images-only', 'registry_host' => 'images.acme.test',
            'credentials' => ['registry' => ['username' => 'bot', 'password' => self::PASSWORD]]]));
        $this->assertSame(RuntimeSource::NOT_FOUND, $otherRegistry->status);

        // 403 the same way
        $this->registryGate = fn (Request $r) => str_contains($r->url(), '/v2/runtimes/catalog/') ? Http::response(['errors' => [['code' => 'DENIED']]], 403) : null;
        $this->assertSame(RuntimeSource::NOT_FOUND, $this->sync($anonymous)->status);
        $this->assertSame(RuntimeSource::AUTH_FAILED, $this->sync($withCredentials)->status);

        foreach ([$anonymous, $withCredentials, $otherRegistry] as $source) {
            $this->assertStringNotContainsString(self::PASSWORD, (string) $source->fresh()->error);
        }
    }

    public function test_a_git_repository_refused_without_credentials_is_not_found(): void
    {
        $answer = "fatal: could not read Username for 'https://git.acme.test': terminal prompts disabled";
        $this->app->instance(GitRunner::class, new class($answer) extends GitRunner
        {
            public function __construct(public string $answer) {}

            public function attempt(array $args, ?string $cwd = null, array $env = [], ?string $input = null, int $timeout = 120, array $config = []): array
            {
                return [128, '', $this->answer];
            }
        });
        $git = fn (array $attributes = []) => RuntimeSource::query()->create($attributes + ['name' => 'g'.bin2hex(random_bytes(3)), 'kind' => 'git', 'scope' => 'platform',
            'url' => 'https://git.acme.test/acme/runtimes.git', 'ref' => 'main']);

        $anonymous = $this->sync($git());
        $this->assertSame(RuntimeSource::NOT_FOUND, $anonymous->status);
        $this->assertStringStartsWith('The repository does not exist or is not public (a private one needs a token or a deploy key): fatal: could not read Username', (string) $anonymous->error);

        $this->app->make(GitRunner::class)->answer = 'remote: Invalid username or password. fatal: Authentication failed for \'https://git.acme.test/acme/runtimes.git/\'';
        $token = $this->sync($git(['credentials' => ['git' => ['token' => self::TOKEN]]]));
        $this->assertSame(RuntimeSource::AUTH_FAILED, $token->status);
        $this->assertStringStartsWith('remote: Invalid username or password.', (string) $token->error);

        // A deploy key is no credential of an HTTPS URL: the read is anonymous
        $keyOnly = $this->sync($git(['credentials' => ['git' => ['ssh_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n-----END OPENSSH PRIVATE KEY-----"]]]));
        $this->assertSame(RuntimeSource::NOT_FOUND, $keyOnly->status);

        // Test connection says the same
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/runtime-sources/test', [
            'kind' => 'git', 'url' => 'https://git.acme.test/acme/runtimes.git', 'ref' => 'main',
        ])->assertOk()->assertJsonPath('data.ok', false)->assertJsonPath('data.status', 'not_found');
    }

    public function test_the_status_of_a_failed_git_command(): void
    {
        $cases = [
            // [message, with credentials, status]
            ["fatal: could not read Username for 'https://github.com': terminal prompts disabled", false, RuntimeSource::NOT_FOUND],
            ["fatal: could not read Username for 'https://github.com': terminal prompts disabled", true, RuntimeSource::AUTH_FAILED],
            ["remote: HTTP Basic: Access denied\nfatal: Authentication failed for 'https://gitlab.acme.test/a/b.git/'", true, RuntimeSource::AUTH_FAILED],
            ["remote: HTTP Basic: Access denied\nfatal: Authentication failed for 'https://gitlab.acme.test/a/b.git/'", false, RuntimeSource::NOT_FOUND],
            ['fatal: unable to access \'https://git.acme.test/a/b.git/\': The requested URL returned error: 403', false, RuntimeSource::NOT_FOUND],
            ['fatal: unable to access \'https://git.acme.test/a/b.git/\': The requested URL returned error: 403', true, RuntimeSource::AUTH_FAILED],
            ["git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.", true, RuntimeSource::AUTH_FAILED],
            ["git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.", false, RuntimeSource::NOT_FOUND],
            // the host says the repository does not exist (for these credentials too)
            ["remote: Repository not found.\nfatal: repository 'https://github.com/acme/gone.git/' not found", true, RuntimeSource::NOT_FOUND],
            ["remote: Repository not found.\nfatal: repository 'https://github.com/acme/gone.git/' not found", false, RuntimeSource::NOT_FOUND],
            ['fatal: unable to access \'https://git.acme.test/a/b.git/\': The requested URL returned error: 404', false, RuntimeSource::NOT_FOUND],
            // a host key that does not match the stored known_hosts
            ["Host key verification failed.\nfatal: Could not read from remote repository.", false, RuntimeSource::AUTH_FAILED],
            // the network
            ["fatal: unable to access 'https://git.acme.test/a/b.git/': Could not resolve host: git.acme.test", false, RuntimeSource::UNREACHABLE],
            ["fatal: unable to access 'https://git.acme.test/a/b.git/': Could not resolve host: git.acme.test", true, RuntimeSource::UNREACHABLE],
            ["fatal: '/nonexistent/repo' does not appear to be a git repository", false, RuntimeSource::UNREACHABLE],
        ];

        foreach ($cases as [$message, $credentials, $status]) {
            $this->assertSame($status, GitSourceReader::failureStatus($message, $credentials), ($credentials ? 'with' : 'without').' credentials: '.$message);
        }
    }

    public function test_masking_covers_the_forms_a_secret_takes(): void
    {
        $source = new RuntimeSource(['credentials' => ['git' => ['token' => self::TOKEN, 'username' => 'bot'], 'registry' => ['username' => 'bot', 'password' => self::PASSWORD]]]);
        $text = 'fatal: https://bot:'.self::TOKEN.'@git.acme.test/x.git; Authorization: Basic '.base64_encode('bot:'.self::TOKEN)
            .' '.rawurlencode(self::PASSWORD).' '.base64_encode('bot:'.self::PASSWORD).' Bearer abcdefghijklmnop';

        $masked = SourceCredentials::maskFor($text, $source);

        foreach ([self::TOKEN, self::PASSWORD, base64_encode('bot:'.self::TOKEN), base64_encode('bot:'.self::PASSWORD), 'abcdefghijklmnop'] as $secret) {
            $this->assertStringNotContainsString($secret, $masked);
        }
    }

    public function test_a_git_token_goes_in_as_a_header_never_in_the_url_or_arguments(): void
    {
        $url = $this->gitRepository(['runtimes/lua/runtime.yaml' => $this->yamlOf($this->luaManifest())]);
        $seen = [];
        $this->app->instance(GitRunner::class, new class($seen) extends GitRunner
        {
            public function __construct(private array &$seen) {}

            public function attempt(array $args, ?string $cwd = null, array $env = [], ?string $input = null, int $timeout = 120, array $config = []): array
            {
                $this->seen[] = ['args' => $args, 'config' => $config];

                return parent::attempt($args, $cwd, $env, $input, $timeout, $config);
            }
        });

        $source = RuntimeSource::query()->create(['name' => 'g', 'kind' => 'git', 'scope' => 'platform', 'url' => $url, 'ref' => 'main',
            'credentials' => ['git' => ['token' => self::TOKEN]]]);
        $this->assertSame(RuntimeSource::OK, app(RuntimeSourceSync::class)->sync($source)->status);

        foreach ($seen as $call) {
            $this->assertStringNotContainsString(self::TOKEN, implode(' ', $call['args']));
            $this->assertSame('Authorization: Basic '.base64_encode('x-access-token:'.self::TOKEN), $call['config']['http.extraHeader']);
            $this->assertSame('never', $call['config']['protocol.allow']);
        }
    }

    // ── API and permissions ─────────────────────────────────────────────────

    public function test_platform_administrators_connect_platform_sources(): void
    {
        $this->publish('runtimes/catalog', [$this->luaManifest()]);
        $admin = User::factory()->create();

        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/runtime-sources', [
            'name' => 'acme', 'kind' => 'oci-catalog', 'url' => 'registry.acme.test/runtimes/catalog:stable', 'public_key' => $this->feedPublicKey,
        ])->assertCreated()
            ->assertJsonPath('data.scope', 'platform')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.runtimes_count', 1)
            ->assertJsonPath('data.signature_verified', true)
            ->json('data.id');

        $this->actingAs($admin, 'sanctum')->getJson("/api/runtime-sources/{$id}")->assertOk()
            ->assertJsonPath('data.runtimes.0.id', 'lua')
            ->assertJsonPath('data.runtimes.0.lifecycle.state', 'active');
        $this->actingAs($admin, 'sanctum')->getJson('/api/runtime-sources')->assertOk()
            ->assertJsonPath('abilities.manage', true)
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.1.name', 'acme');

        // validation names the field
        $this->actingAs($admin, 'sanctum')->postJson('/api/runtime-sources', ['name' => 'x', 'kind' => 'git', 'url' => 'file:///etc'])->assertStatus(422)->assertJsonValidationErrors('url');
        $this->actingAs($admin, 'sanctum')->postJson('/api/runtime-sources', ['name' => 'y', 'kind' => 'git', 'url' => 'https://git.acme.test/x.git', 'ssh_private_key' => 'k'])->assertStatus(422)->assertJsonValidationErrors('credentials');
        $this->actingAs($admin, 'sanctum')->postJson('/api/runtime-sources', ['name' => 'acme', 'kind' => 'oci-catalog', 'url' => 'registry.acme.test/a/b:c'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->actingAs($admin, 'sanctum')->postJson('/api/runtime-sources', ['name' => 'z', 'kind' => 'oci-catalog', 'url' => 'registry.acme.test/a/b:c', 'public_key' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('public_key');
    }

    public function test_members_connect_sources_of_their_organization_while_custom_runtimes_are_on(): void
    {
        User::factory()->create(); // the Owner
        $member = User::factory()->create(); // a Developer: runtimes.use_custom
        $body = ['name' => 'mine', 'kind' => 'oci-catalog', 'url' => 'registry.acme.test/runtimes/catalog:stable'];

        $this->actingAs($member, 'sanctum')->getJson('/api/runtime-sources')->assertForbidden();
        $this->actingAs($member, 'sanctum')->postJson('/api/runtime-sources', $body)->assertForbidden();

        Setting::set('custom_runtimes_enabled', 'true');
        $this->publish('runtimes/catalog', [$this->luaManifest()]);
        $id = $this->actingAs($member, 'sanctum')->postJson('/api/runtime-sources', $body)->assertCreated()
            ->assertJsonPath('data.scope', 'organization')->json('data.id');
        $this->assertSame(Runtime::SOURCE_CUSTOM, Runtime::query()->findOrFail('lua')->source);

        // the member sees the organization's sources only, not RunR's catalog or platform sources
        $platform = $this->ociSource(['name' => 'platform-one']);
        $ids = array_column($this->actingAs($member, 'sanctum')->getJson('/api/runtime-sources')->assertOk()->json('data'), 'id');
        $this->assertSame([$id], $ids);
        $this->actingAs($member, 'sanctum')->getJson("/api/runtime-sources/{$platform->id}")->assertNotFound();
        $this->actingAs($member, 'sanctum')->postJson("/api/runtime-sources/{$platform->id}/sync")->assertNotFound();
        $this->actingAs($member, 'sanctum')->getJson('/api/runtime-catalog')->assertForbidden();
    }

    public function test_test_connection_reads_without_saving(): void
    {
        $this->publish('runtimes/catalog', [$this->luaManifest(), $this->luaManifest('python')]);
        $admin = User::factory()->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/runtime-sources/test', [
            'kind' => 'oci-catalog', 'url' => 'registry.acme.test/runtimes/catalog:stable', 'public_key' => $this->feedPublicKey,
        ])->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.signature_verified', true)
            ->assertJsonPath('data.runtimes.0', ['id' => 'lua', 'path' => 'runtimes/lua/runtime.yaml', 'valid' => true])
            ->assertJsonPath('data.runtimes.1.valid', false);

        $this->assertNull(Runtime::query()->find('lua'));
        $this->assertSame(0, RuntimeSource::query()->where('is_default', false)->count(), 'nothing saved');

        // A catalog the registry does not have (404): not found
        $this->actingAs($admin, 'sanctum')->postJson('/api/runtime-sources/test', ['kind' => 'oci-catalog', 'url' => 'registry.acme.test/missing/catalog:stable'])->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.status', 'not_found');
    }

    public function test_sync_now_answers_502_for_a_source_that_does_not_verify(): void
    {
        $admin = User::factory()->create();
        $this->publish('runtimes/catalog', [$this->luaManifest()]);
        $source = $this->ociSource(['public_key' => openssl_pkey_get_details(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']))['key']]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/runtime-sources/{$source->id}/sync")->assertStatus(502)
            ->assertJsonPath('data.status', 'invalid_signature');
    }

    public function test_removing_a_source_keeps_runtimes_with_projects_orphaned(): void
    {
        $this->publish('runtimes/catalog', [$this->luaManifest(), $this->luaManifest('moon')]);
        $source = $this->sync($this->ociSource());
        $this->runtimeProject('moon');
        $admin = User::factory()->create();

        Bus::fake([DeleteRuntimeSourceSecretsJob::class]);
        $source->forceFill(['credentials' => ['registry' => ['username' => 'bot', 'password' => self::PASSWORD]]])->save();
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/runtime-sources/{$source->id}")->assertNoContent();
        Bus::assertDispatched(DeleteRuntimeSourceSecretsJob::class, fn ($job) => $job->sourceId === $source->id);

        $this->assertNull(Runtime::query()->find('lua'), 'no project: deleted');
        $moon = Runtime::query()->findOrFail('moon');
        $this->assertNotNull($moon->orphaned_at);
        $this->assertNull($moon->source_id);
        $this->assertNull(RuntimeSource::query()->find($source->id));
    }
}
