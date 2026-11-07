<?php

namespace Tests\Feature\Runtimes;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Jobs\AnnounceRuntimePatchesJob;
use App\Jobs\EnforceRuntimePatchesJob;
use App\Models\ExecutorImage;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchFeed;
use App\Models\RuntimePatchMigration;
use App\Models\RuntimeSource;
use App\Models\User;
use App\Services\Runtimes\Patches\PatchFeedSync;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Services\Runtimes\Patches\RuntimePatchMigrations;
use App\Services\Runtimes\Patches\RuntimePatchNotifier;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Runtimes\Sources\CatalogPayload;
use App\Services\Runtimes\Sources\RuntimeSourceSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsRuntimeSources;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Patches of a source's own runtimes (docs/runtimes.md#patches-of-a-source): from its signed
 * catalog or its own signed patch feed, verified with the source's key, stored for its runtimes
 * only — and then the same flow as the built-ins: digests, project state, deadlines, automatic
 * migration. RunR's feed never patches a source's runtime, and a source never patches another's.
 */
class RuntimeSourcePatchesTest extends TestCase
{
    use ActsWithRoles, BuildsRuntimeSources, RefreshDatabase, RegistersRuntimes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
        $this->setUpRuntimeSources();
        Bus::fake([AnnounceRuntimePatchesJob::class]);
        Event::fake([DeploymentCreated::class, DeploymentStatusChanged::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownRuntimeSources();
        parent::tearDown();
    }

    private function luaPatch(string $tag, string $level = 'security', array $extra = []): array
    {
        return array_merge([
            'id' => "lua-5.4-{$tag}",
            'runtime' => 'lua',
            'version' => '5.4',
            'tag' => $tag,
            'image' => 'registry.acme.test/runtimes/lua',
            'digest' => self::digestOf("lua:{$tag}"),
            'level' => $level,
            'reason' => ['en' => "Lua {$tag} fixes things", 'tr' => "Lua {$tag} bir şeyleri düzeltir"],
            'cves' => ['CVE-2026-4242'],
            'published_at' => now()->subDay()->toIso8601String(),
            'deadline_at' => in_array($level, ['critical', 'zero-day'], true) ? now()->subHour()->toIso8601String() : null,
        ], $extra);
    }

    private function syncCatalog(array $patches, ?RuntimeSource $source = null): RuntimeSource
    {
        $this->publishArtifact('runtimes/catalog', 'stable', CatalogPayload::MEDIA_TYPE, $this->catalog([$this->luaManifest()], $patches));
        $source = app(RuntimeSourceSync::class)->sync($source ?? $this->ociSource(), true);
        app(RuntimeRegistry::class)->forget();
        app(RuntimePatches::class)->flush();

        return $source;
    }

    public function test_patches_of_a_signed_catalog_patch_its_runtimes(): void
    {
        $source = $this->syncCatalog([$this->luaPatch('5.4.7-r1')]);

        $patch = RuntimePatch::query()->sole();
        $this->assertSame('lua-5.4-5.4.7-r1~'.strtolower($source->id), $patch->id);
        $this->assertSame($source->id, $patch->source_id);
        $this->assertSame(self::digestOf('lua:5.4.7-r1'), ExecutorImage::query()->where('runtime', 'lua')->where('version', '5.4')->value('digest'));

        [$project, $version] = $this->runtimeProject('lua');
        $project->forceFill(['runtime_version' => '5.4'])->save();
        $this->liveOn($project, $version, null, now()->subDays(3));
        $state = app(RuntimePatches::class)->state($project->fresh());
        $this->assertSame(['patch_available', 'security'], [$state['state'], $state['level']]);

        // the workload runs the patch by digest
        $executor = ExecutorImage::resolve('lua', '5.4');
        $resolved = app(RuntimePatches::class)->resolve($project->fresh(), $executor, 'registry.acme.test/runtimes/lua:5.4');
        $this->assertSame('registry.acme.test/runtimes/lua@'.self::digestOf('lua:5.4.7-r1'), $resolved->image);
    }

    public function test_a_source_can_not_patch_a_runtime_it_does_not_provide(): void
    {
        $source = $this->syncCatalog([
            $this->luaPatch('5.4.7-r1'),
            $this->luaPatch('3.14.99-r1', 'zero-day', ['id' => 'py-evil', 'runtime' => 'python', 'version' => '3.14', 'image' => 'ghcr.io/brf-tech/runr/exec-python']),
        ]);

        $this->assertSame(['lua'], RuntimePatch::query()->pluck('runtime')->all());
        $this->assertSame(RuntimeSource::PARTIAL, $source->status);
        $this->assertTrue(collect($source->problems)->contains(fn ($p) => str_contains($p['message'], "names the runtime 'python', which this source does not provide")));
        $this->assertNull(ExecutorImage::query()->where('runtime', 'python')->where('version', '3.14')->value('digest'));
    }

    public function test_an_unsigned_catalog_carries_no_patches(): void
    {
        $this->publishArtifact('runtimes/catalog', 'stable', CatalogPayload::MEDIA_TYPE, $this->catalog([$this->luaManifest()], [$this->luaPatch('5.4.7-r1')]), false);

        $source = app(RuntimeSourceSync::class)->sync($this->ociSource(['public_key' => null]), true);

        $this->assertSame(RuntimeSource::OK, $source->status);
        $this->assertFalse($source->signature_verified);
        $this->assertSame(0, RuntimePatch::query()->count());
    }

    public function test_runrs_feed_never_patches_a_source_runtime_and_keeps_the_sources_patches(): void
    {
        $this->syncCatalog([$this->luaPatch('5.4.7-r1')]);

        // RunR's patch feed (a built-in's patch, and one that names lua)
        $this->publishFeed($this->feed([
            $this->feedPatch('3.14.9-r1'),
            $this->luaPatch('5.4.8-r1', 'zero-day', ['id' => 'lua-from-runr']),
        ]));
        app(PatchFeedSync::class)->sync(true);
        app(RuntimePatches::class)->flush();

        $this->assertTrue(RuntimePatch::query()->whereKey('lua-5.4-5.4.7-r1~'.strtolower(RuntimeSource::query()->where('name', 'acme-runtimes')->value('id')))->value('in_feed'), 'RunR\'s feed does not drop it');
        $this->assertSame(['lua-5.4-5.4.7-r1'], app(RuntimePatches::class)->of('lua', '5.4')->map(fn (RuntimePatch $p) => explode('~', $p->id)[0])->all());
        $this->assertSame(self::digestOf('lua:5.4.7-r1'), ExecutorImage::query()->where('runtime', 'lua')->where('version', '5.4')->value('digest'));
        $this->assertSame(self::digestOf('python:3.14.9-r1'), ExecutorImage::query()->where('runtime', 'python')->where('version', '3.14')->value('digest'));
    }

    public function test_a_source_patch_feed_is_verified_with_the_source_key(): void
    {
        $this->publishArtifact('runtimes/catalog', 'stable', CatalogPayload::MEDIA_TYPE, $this->catalog([$this->luaManifest()]));
        $this->publishArtifact('runtimes/patches', 'feed', PatchFeedSync::MEDIA_TYPE, $this->feed([$this->luaPatch('5.4.7-r1', 'critical')]));
        $source = $this->ociSource(['patch_feed' => 'registry.acme.test/runtimes/patches:feed']);

        $source = app(RuntimeSourceSync::class)->sync($source, true);

        $this->assertSame(RuntimeSource::OK, $source->status, json_encode($source->problems));
        $patch = RuntimePatch::query()->sole();
        $this->assertSame([$source->id, 'critical'], [$patch->source_id, $patch->level]);
        $feed = RuntimePatchFeed::query()->where('source_id', $source->id)->sole();
        $this->assertSame(RuntimePatchFeed::OK, $feed->status);
        $this->assertTrue($feed->signature_verified);

        // a feed signed with another key is refused, the source says so
        $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($other, $otherKey);
        $this->publishArtifact('runtimes/patches', 'feed', PatchFeedSync::MEDIA_TYPE, $this->feed([$this->luaPatch('5.4.9-r1')]), $otherKey);
        $source = app(RuntimeSourceSync::class)->sync($source->fresh(), true);
        $this->assertSame(RuntimeSource::PARTIAL, $source->status);
        $this->assertSame(RuntimePatchFeed::INVALID_SIGNATURE, $feed->fresh()->status);
        $this->assertSame(1, RuntimePatch::query()->count());
    }

    public function test_the_deadline_of_a_source_patch_migrates_its_projects(): void
    {
        $owner = $this->userWithRole('owner');
        $this->syncCatalog([$this->luaPatch('5.4.7-r1', 'zero-day')]);
        [$project, $version] = $this->runtimeProject('lua');
        $project->forceFill(['runtime_version' => '5.4', 'owner_id' => $owner->id])->save();
        $this->liveOn($project, $version, null, now()->subDays(3));
        $this->assertSame('overdue', app(RuntimePatches::class)->state($project->fresh())['state']);

        (new EnforceRuntimePatchesJob)->handle(app(RuntimePatchMigrations::class), app(RuntimePatchNotifier::class));

        $migration = RuntimePatchMigration::query()->sole();
        $this->assertSame([RuntimePatchMigration::AUTO, (string) RuntimePatch::query()->value('id')], [$migration->trigger, $migration->patch_id]);
    }

    public function test_the_source_detail_lists_its_patches(): void
    {
        $source = $this->syncCatalog([$this->luaPatch('5.4.7-r1')]);
        $admin = User::query()->first() ?? User::factory()->create();

        $this->actingAs($admin, 'sanctum')->getJson("/api/runtime-sources/{$source->id}")->assertOk()
            ->assertJsonPath('data.patches.0.tag', '5.4.7-r1')
            ->assertJsonPath('data.patches.0.source_id', $source->id);
    }
}
