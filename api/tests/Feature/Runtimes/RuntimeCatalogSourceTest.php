<?php

namespace Tests\Feature\Runtimes;

use App\Jobs\AnnounceRuntimePatchesJob;
use App\Models\ExecutorImage;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\RuntimeSource;
use App\Models\User;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Runtimes\Sources\BuiltinOffer;
use App\Services\Runtimes\Sources\RuntimeSourceSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsRuntimeSources;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * RunR's default runtime catalog (docs/runtimes.md#runtime-sources): the signed OCI catalog of
 * BRF-Tech/runr-runtimes is the source of the built-in runtimes, read like the patch feed (a HEAD,
 * the signature before the content); builtin.json is only the offline fallback; a language the
 * catalog gains appears without a RunR release; the Built-in runtimes setting replaces
 * EXECUTOR_RUNTIMES.
 */
class RuntimeCatalogSourceTest extends TestCase
{
    use BuildsRuntimeSources, RefreshDatabase, RegistersRuntimes;

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

    private function syncDefault(bool $force = false): RuntimeSource
    {
        return app(RuntimeSourceSync::class)->sync(RuntimeSource::default(), $force);
    }

    private function catalogIds(): array
    {
        return array_keys(app(BuiltinCatalog::class)->all());
    }

    public function test_until_the_catalog_is_read_the_bundle_is_in_use(): void
    {
        $this->assertSame(BuiltinCatalog::MODE_BUNDLE, app(BuiltinCatalog::class)->mode());
        $this->assertSame(array_column($this->bundledManifests(), 'id'), $this->catalogIds());

        // Unreachable (air-gapped): the bundle stays in use, the source says why
        $this->registryGate = fn () => throw new ConnectionException('cURL error 6: Could not resolve host: ghcr.io');
        $source = $this->syncDefault();
        $this->assertSame(RuntimeSource::UNREACHABLE, $source->status);
        $this->assertNull($source->synced_at);
        $this->assertSame(BuiltinCatalog::MODE_BUNDLE, app(BuiltinCatalog::class)->mode());
    }

    /**
     * While the catalog package does not exist or is not public, ghcr.io answers an anonymous read
     * with 401 / 403 — after handing out an anonymous pull token. The default source reads without
     * credentials: that is not_found ("not found or not public"), never auth_failed, and the
     * bundle stays in use.
     */
    public function test_a_missing_or_private_catalog_is_not_found_not_an_auth_failure(): void
    {
        $tokens = 0;
        foreach ([401, 403] as $status) {
            $this->registryGate = function (Request $r) use ($status, &$tokens) {
                if (str_starts_with($r->url(), 'https://ghcr.io/token')) {
                    $tokens++;

                    return Http::response(['token' => 'anonymous-pull-token']);
                }
                $challenge = 'Bearer realm="https://ghcr.io/token",service="ghcr.io",scope="repository:brf-tech/runr/runtime-catalog:pull"';

                return $r->hasHeader('Authorization')
                    ? Http::response(['errors' => [['code' => $status === 401 ? 'UNAUTHORIZED' : 'DENIED', 'message' => 'requested access to the resource is denied']]], $status)
                    : Http::response(['errors' => [['code' => 'UNAUTHORIZED']]], 401, ['WWW-Authenticate' => $challenge]);
            };

            $source = $this->syncDefault(true);

            $this->assertSame(RuntimeSource::NOT_FOUND, $source->status, "HTTP {$status}");
            $this->assertStringContainsString('no such catalog, or it is not public', (string) $source->error);
            $this->assertStringContainsString("HTTP {$status}", (string) $source->error);
            $this->assertStringNotContainsString('refused the credentials', (string) $source->error);
            $this->assertNull($source->synced_at);
            $this->assertSame(BuiltinCatalog::MODE_BUNDLE, app(BuiltinCatalog::class)->mode());
            $this->assertSame(array_column($this->bundledManifests(), 'id'), $this->catalogIds());
        }
        $this->assertSame(2, $tokens, 'each read asked the token service once');

        // A registry without a token service answers 401 straight away; one without the tag 404
        $this->registryGate = fn () => Http::response('', 401);
        $this->assertSame(RuntimeSource::NOT_FOUND, $this->syncDefault(true)->status);
        $this->registryGate = null;
        $source = $this->syncDefault(true);
        $this->assertSame(RuntimeSource::NOT_FOUND, $source->status);
        $this->assertStringContainsString('no such catalog (HTTP 404)', (string) $source->error);

        // The API and its list of failed reads say the same
        $admin = User::factory()->create();
        $this->actingAs($admin, 'sanctum')->getJson('/api/runtime-sources')->assertOk()
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.0.status', 'not_found');
        $this->assertContains(RuntimeSource::NOT_FOUND, RuntimeSource::FAILED);
    }

    public function test_the_signed_catalog_becomes_the_built_in_runtimes(): void
    {
        $digest = $this->publishDefaultCatalog($this->bundledManifests());

        $source = $this->syncDefault();

        $this->assertSame(RuntimeSource::OK, $source->status);
        $this->assertSame($digest, $source->revision);
        $this->assertTrue($source->signature_verified);
        $this->assertSame(count($this->bundledManifests()), $source->runtimes_count);
        $this->assertSame(BuiltinCatalog::MODE_SOURCE, app(BuiltinCatalog::class)->mode());

        // the rows of the built-ins (from the migration) were adopted: the catalog's manifest on each
        $python = Runtime::query()->findOrFail('python');
        $this->assertSame(Runtime::SOURCE_BUILTIN, $python->source);
        $this->assertSame($source->id, $python->source_id);
        $this->assertSame('runtimes/python/runtime.yaml', $python->source_path);
        $this->assertSame('Python', $python->manifest['name']);
        $this->assertSame(array_column($this->bundledManifests(), 'id'), $this->catalogIds());
    }

    public function test_an_unchanged_catalog_costs_one_head(): void
    {
        $this->publishDefaultCatalog($this->bundledManifests());
        $this->syncDefault();
        $this->registryRequests = [];

        $this->syncDefault();

        $this->assertSame(['HEAD /v2/brf-tech/runr/runtime-catalog/manifests/stable'], $this->registryRequests);
    }

    public function test_a_new_language_in_the_catalog_appears_without_a_release(): void
    {
        $this->publishDefaultCatalog($this->bundledManifests());
        $this->syncDefault();
        $user = User::factory()->create();
        $this->assertNotContains('elixir', array_column($this->actingAs($user, 'sanctum')->getJson('/api/runtimes')->json('data'), 'id'));

        // runr-runtimes publishes a catalog with Elixir; the next check reads it
        $this->publishDefaultCatalog([...$this->bundledManifests(), $this->elixirManifest()]);
        $this->syncDefault();
        app(RuntimeRegistry::class)->forget();

        $elixir = collect($this->actingAs($user, 'sanctum')->getJson('/api/runtimes')->assertOk()->json('data'))->firstWhere('id', 'elixir');
        $this->assertNotNull($elixir, 'the new built-in is offered (Built-in runtimes: all)');
        $this->assertSame('builtin', $elixir['source']);
        $this->assertSame(['1.18', '1.19'], array_column($elixir['versions'], 'version'));
        $this->assertSame('2027-03-31', $elixir['versions'][0]['eol_at']);
        // a project can be created on it
        $this->assertNull(app(RuntimeRegistry::class)->unavailableReason('elixir', null, 'http'));
    }

    public function test_a_selected_offer_leaves_new_languages_out(): void
    {
        $this->publishDefaultCatalog([...$this->bundledManifests(), $this->elixirManifest()]);
        BuiltinOffer::save(['python', 'go']);
        $this->syncDefault();
        $registry = app(RuntimeRegistry::class);
        $registry->forget();

        $this->assertSame(['python', 'go'], $registry->builtinIds());
        $this->assertNotNull($registry->unavailableReason('elixir', null, 'http'));

        BuiltinOffer::save(null);
        $registry->forget();
        $this->assertContains('elixir', $registry->builtinIds());
    }

    public function test_executor_runtimes_is_only_the_first_default_of_the_setting(): void
    {
        config(['executor.runtimes' => ['python', 'go']]);
        $registry = app(RuntimeRegistry::class);
        $this->assertSame(['python', 'go'], $registry->builtinIds());
        $this->assertFalse(BuiltinOffer::saved());

        BuiltinOffer::save(['python', 'nodejs']);
        $registry->forget();
        $this->assertSame(['python', 'nodejs'], $registry->builtinIds());
    }

    public function test_a_catalog_that_does_not_verify_is_refused_and_the_last_good_one_stays(): void
    {
        $this->publishDefaultCatalog($this->bundledManifests());
        $good = $this->syncDefault()->revision;

        $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($other, $otherKey);
        $this->publishDefaultCatalog([...$this->bundledManifests(), $this->elixirManifest()], $otherKey);

        $source = $this->syncDefault();

        $this->assertSame(RuntimeSource::INVALID_SIGNATURE, $source->status);
        $this->assertSame($good, $source->revision);
        $this->assertNull(Runtime::query()->find('elixir'));
        $this->assertSame(BuiltinCatalog::MODE_SOURCE, app(BuiltinCatalog::class)->mode());
    }

    public function test_an_unsigned_default_catalog_is_refused(): void
    {
        $this->publishDefaultCatalog($this->bundledManifests(), false);

        $source = $this->syncDefault();

        $this->assertSame(RuntimeSource::INVALID_SIGNATURE, $source->status);
        $this->assertSame(BuiltinCatalog::MODE_BUNDLE, app(BuiltinCatalog::class)->mode());
    }

    public function test_a_bad_manifest_is_a_problem_with_its_path_and_the_others_are_read(): void
    {
        $broken = $this->elixirManifest(['run' => ['mode' => 'exec', 'command' => null]]);
        unset($broken['run']['command']);
        $this->publishDefaultCatalog([...$this->bundledManifests(), $broken]);

        $source = $this->syncDefault();

        $this->assertSame(RuntimeSource::PARTIAL, $source->status);
        $this->assertContains(['file' => 'runtimes/elixir/runtime.yaml', 'runtime' => 'elixir', 'path' => '/run/command', 'message' => 'is required in exec mode'], $source->problems);
        $this->assertNull(Runtime::query()->find('elixir'));
        $this->assertSame(BuiltinCatalog::MODE_SOURCE, app(BuiltinCatalog::class)->mode());
        $this->assertContains('python', $this->catalogIds());
    }

    public function test_switching_the_catalog_off_uses_the_bundle(): void
    {
        $this->publishDefaultCatalog([...$this->bundledManifests(), $this->elixirManifest()]);
        $this->syncDefault();
        $this->assertContains('elixir', $this->catalogIds());

        config(['runtime_sources.default.reference' => '']);
        BuiltinCatalog::flush();
        $source = $this->syncDefault();

        $this->assertSame(RuntimeSource::DISABLED, $source->status);
        $this->assertSame(BuiltinCatalog::MODE_BUNDLE, app(BuiltinCatalog::class)->mode());
        $this->assertNotContains('elixir', $this->catalogIds());
        // a project of the catalog-only runtime keeps its manifest (the row) and keeps deploying
        $this->assertNotNull(app(RuntimeRegistry::class)->manifest('elixir'));
        $this->assertNull(app(RuntimeRegistry::class)->deployBlockedReason('elixir', null));
        $this->assertNotNull(app(RuntimeRegistry::class)->unavailableReason('elixir', null, 'http'), 'not offered for new projects');
    }

    public function test_a_built_in_the_catalog_drops_is_orphaned_and_its_projects_keep_running(): void
    {
        $this->publishDefaultCatalog([...$this->bundledManifests(), $this->elixirManifest()]);
        $this->syncDefault();
        $project = Project::create(['name' => 'shop', 'service_type' => 'xaas', 'runtime' => 'elixir', 'runtime_version' => '1.19']);

        $this->publishDefaultCatalog($this->bundledManifests());
        $this->syncDefault();

        $elixir = Runtime::query()->findOrFail('elixir');
        $this->assertNotNull($elixir->orphaned_at);
        $registry = app(RuntimeRegistry::class);
        $registry->forget();
        $this->assertNull($registry->deployBlockedReason('elixir', null));
        $this->assertFalse($project->fresh()->isRuntimeEol());
        $this->assertNotNull($registry->unavailableReason('elixir', null, 'http'));
        // the sunset at the end of the grace period
        $this->assertSame(now()->addDays(90)->toDateString(), ExecutorImage::query()->where('runtime', 'elixir')->where('version', '1.19')->first()->sunset_at->toDateString());

        $this->travel(91)->days();
        $this->assertTrue($project->fresh()->isRuntimeEol());
    }

    public function test_the_api_says_which_catalog_is_in_use_and_saves_the_offer(): void
    {
        $admin = User::factory()->create(); // the first user: Owner (runtimes.manage)

        $this->actingAs($admin, 'sanctum')->getJson('/api/runtime-catalog')->assertOk()
            ->assertJsonPath('data.mode', 'bundle')
            ->assertJsonPath('data.builtin_runtimes', null)
            ->assertJsonPath('data.saved', false);

        $this->publishDefaultCatalog([...$this->bundledManifests(), $this->elixirManifest()]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/runtime-sources/'.RuntimeSource::default()->id.'/sync')->assertOk()
            ->assertJsonPath('data.status', 'ok');

        $this->actingAs($admin, 'sanctum')->getJson('/api/runtime-catalog')->assertOk()
            ->assertJsonPath('data.mode', 'source');

        $this->actingAs($admin, 'sanctum')->putJson('/api/runtime-catalog', ['builtin_runtimes' => ['python', 'elixir']])->assertOk()
            ->assertJsonPath('data.builtin_runtimes', ['python', 'elixir'])
            ->assertJsonPath('data.saved', true);
        $this->actingAs($admin, 'sanctum')->putJson('/api/runtime-catalog', ['builtin_runtimes' => ['nope']])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson('/api/runtime-catalog', ['builtin_runtimes' => null])->assertOk()
            ->assertJsonPath('data.builtin_runtimes', null);

        $member = User::factory()->create();
        $this->actingAs($member, 'sanctum')->getJson('/api/runtime-catalog')->assertForbidden();
    }

    public function test_the_default_source_is_read_only_but_can_be_switched_off(): void
    {
        $admin = User::factory()->create();
        $id = RuntimeSource::default()->id;

        $this->actingAs($admin, 'sanctum')->putJson("/api/runtime-sources/{$id}", ['url' => 'evil.example/x:y'])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/runtime-sources/{$id}")->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson("/api/runtime-sources/{$id}", ['is_enabled' => false, 'interval_minutes' => 30])->assertOk()
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.interval_minutes', 30)
            ->assertJsonPath('data.status', 'disabled');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
    }

    public function test_the_scheduler_reads_due_sources_only(): void
    {
        $this->publishDefaultCatalog($this->bundledManifests());
        $this->artisan('runtime-sources:sync')->assertExitCode(0);
        $this->assertSame(RuntimeSource::OK, RuntimeSource::default()->status);
        $this->registryRequests = [];

        // within the interval: nothing
        $this->artisan('runtime-sources:sync')->assertExitCode(0);
        $this->assertSame([], $this->registryRequests);

        $this->travel(16)->minutes();
        $this->artisan('runtime-sources:sync')->assertExitCode(0);
        $this->assertSame(['HEAD /v2/brf-tech/runr/runtime-catalog/manifests/stable'], $this->registryRequests);
    }
}
