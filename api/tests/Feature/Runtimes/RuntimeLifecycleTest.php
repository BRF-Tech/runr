<?php

namespace Tests\Feature\Runtimes;

use App\Jobs\AnnounceRuntimePatchesJob;
use App\Jobs\RuntimeEolRemindersJob;
use App\Models\ExecutorImage;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\RuntimeSource;
use App\Models\Setting;
use App\Models\User;
use App\Services\Runtimes\Patches\RuntimePatchNotifier;
use App\Services\Runtimes\RuntimeLifecycle;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Runtimes\Sources\CatalogPayload;
use App\Services\Runtimes\Sources\RuntimeSourceSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsRuntimeSources;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * The lifecycle of runtimes and versions (docs/runtimes.md#lifecycle) is the same whatever the
 * runtime's source — RunR's catalog, a platform runtime, an organization's runtime, either one
 * registered by hand or synced from a source: the end of life, deprecated and sunset dates, the
 * reminders, the picker, the refusal of saves and deploys after the end, pinned and unpinned
 * projects, a runtime that leaves its source.
 */
class RuntimeLifecycleTest extends TestCase
{
    use ActsWithRoles, BuildsRuntimeSources, RefreshDatabase, RegistersRuntimes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
        $this->setUpRuntimeSources();
        Bus::fake([AnnounceRuntimePatchesJob::class]);
        Mail::fake();
        Setting::set('custom_runtimes_enabled', 'true');
    }

    protected function tearDown(): void
    {
        $this->tearDownRuntimeSources();
        parent::tearDown();
    }

    /** A runtime with an ended version, a deprecated one with a sunset, and a current default. */
    private function lifecycleManifest(string $id, array $override = []): array
    {
        return array_replace([
            'apiVersion' => 'runr.sh/v1',
            'kind' => 'Runtime',
            'id' => $id,
            'name' => ucfirst($id),
            'extensions' => ['txt'],
            'versions' => [
                ['version' => '1.0', 'image' => "registry.acme.test/{$id}:1.0", 'eol' => now()->subDay()->toDateString()],
                ['version' => '2.0', 'image' => "registry.acme.test/{$id}:2.0", 'deprecated' => true, 'sunset' => now()->addDays(20)->toDateString()],
                ['version' => '3.0', 'image' => "registry.acme.test/{$id}:3.0", 'default' => true],
            ],
            'run' => ['mode' => 'exec', 'command' => ['cat']],
        ], $override);
    }

    public static function kinds(): array
    {
        return [
            'built-in (RunR catalog)' => ['builtin'],
            'platform, by hand' => ['platform'],
            'organization, by hand' => ['custom'],
            'platform source' => ['platform-source'],
            'organization source' => ['organization-source'],
        ];
    }

    /** The runtime $id of the kind, from $manifest. */
    private function provide(string $kind, array $manifest): void
    {
        match ($kind) {
            'builtin' => $this->syncCatalog(RuntimeSource::default(), 'brf-tech/runr/runtime-catalog', [...$this->bundledManifests(), $manifest]),
            'platform' => $this->registerRuntime($this->yamlOf($manifest)),
            'custom' => $this->registerRuntime($this->yamlOf($manifest), [], Runtime::SOURCE_CUSTOM),
            'platform-source' => $this->syncCatalog(RuntimeSource::query()->where('name', 'acme-runtimes')->first() ?? $this->ociSource(), 'runtimes/catalog', [$manifest]),
            'organization-source' => $this->syncCatalog(RuntimeSource::query()->where('name', 'acme-runtimes')->first() ?? $this->ociSource(['scope' => RuntimeSource::SCOPE_ORGANIZATION]), 'runtimes/catalog', [$manifest]),
        };
        app(RuntimeRegistry::class)->forget();
    }

    private function syncCatalog(RuntimeSource $source, string $repository, array $manifests): void
    {
        $this->publishArtifact($repository, 'stable', CatalogPayload::MEDIA_TYPE, $this->catalog($manifests));
        $source = app(RuntimeSourceSync::class)->sync($source, true);
        $this->assertSame(RuntimeSource::OK, $source->status, json_encode($source->problems).' '.$source->error);
    }

    private function version(string $runtime, string $version): ExecutorImage
    {
        return ExecutorImage::query()->where('runtime', $runtime)->where('version', $version)->firstOrFail();
    }

    #[DataProvider('kinds')]
    public function test_versions_end_and_their_projects_can_no_longer_save_or_deploy(string $kind): void
    {
        $this->provide($kind, $this->lifecycleManifest('zeta'));

        $old = $this->version('zeta', '1.0');
        $deprecated = $this->version('zeta', '2.0');
        $this->assertSame(RuntimeLifecycle::EOL, RuntimeLifecycle::version($old)['state']);
        $this->assertSame(RuntimeLifecycle::DEPRECATED, RuntimeLifecycle::version($deprecated)['state']);
        $this->assertSame(now()->toDateString(), $deprecated->deprecated_at->toDateString(), 'true: deprecated from the first sync');
        $this->assertSame(now()->addDays(20)->toDateString(), $deprecated->endsAt()->toDateString());
        $this->assertSame('sunset', $deprecated->endKind());
        $this->assertSame(RuntimeLifecycle::ACTIVE, RuntimeLifecycle::version($this->version('zeta', '3.0'))['state']);

        // the pinned project on the ended version is refused, the deprecated one still works
        $ended = Project::create(['name' => 'old', 'service_type' => 'xaas', 'runtime' => 'zeta', 'runtime_version' => '1.0']);
        $onDeprecated = Project::create(['name' => 'dep', 'service_type' => 'xaas', 'runtime' => 'zeta', 'runtime_version' => '2.0']);
        $unpinned = Project::create(['name' => 'free', 'service_type' => 'xaas', 'runtime' => 'zeta']);
        $this->assertTrue($ended->isRuntimeEol());
        $this->assertNull(ExecutorImage::resolve('zeta', '1.0'));
        $this->assertFalse($onDeprecated->isRuntimeEol());
        $this->assertFalse($unpinned->isRuntimeEol());

        // after the sunset: refused too; the project without a version follows the default
        $this->travel(21)->days();
        $this->assertTrue($onDeprecated->fresh()->isRuntimeEol());
        $this->assertNull(ExecutorImage::resolve('zeta', '2.0'));
        $this->assertFalse($unpinned->fresh()->isRuntimeEol());
        $this->assertSame('3.0', ExecutorImage::resolve('zeta')->version);
    }

    #[DataProvider('kinds')]
    public function test_the_sunset_is_reminded_like_an_end_of_life(string $kind): void
    {
        $owner = $this->userWithRole('owner');
        $this->provide($kind, $this->lifecycleManifest('zeta'));
        $project = Project::create(['name' => 'dep', 'service_type' => 'xaas', 'runtime' => 'zeta', 'runtime_version' => '2.0']);
        $project->forceFill(['owner_id' => $owner->id])->save();

        (new RuntimeEolRemindersJob)->handle(app(RuntimePatchNotifier::class));
        (new RuntimeEolRemindersJob)->handle(app(RuntimePatchNotifier::class));

        $reminders = Notification::query()->where('type', 'runtime_eol')->where('data->event', 'sunset')->get();
        $this->assertCount(1, $reminders->where('user_id', $owner->id), 'once per threshold');
        $reminder = $reminders->first();
        $this->assertSame(4, $reminder->data['weeks']);
        $this->assertSame(now()->addDays(20)->toDateString(), $reminder->data['ends_at']);
        $this->assertStringContainsString('sunset', $reminder->title);
    }

    #[DataProvider('kinds')]
    public function test_a_sunset_runtime_takes_no_new_projects_and_its_projects_stop_deploying(string $kind): void
    {
        $manifest = $this->lifecycleManifest('omega', [
            'deprecated' => now()->subDays(30)->toDateString(),
            'sunset' => now()->subDay()->toDateString(),
            'replacement' => 'python',
            'notice' => 'Omega moved to Python.',
        ]);
        $this->provide($kind, $manifest);
        $registry = app(RuntimeRegistry::class);
        $unpinned = Project::create(['name' => 'free', 'service_type' => 'xaas', 'runtime' => 'omega']);

        $this->assertStringContainsString('sunset', (string) $registry->unavailableReason('omega', null, 'http'));
        $this->assertTrue($unpinned->isRuntimeEol(), 'the default version ends with the runtime');
        $state = RuntimeLifecycle::runtime(Runtime::query()->findOrFail('omega'), $registry->manifest('omega'));
        $this->assertSame(['state' => 'sunset', 'replacement' => 'python', 'notice' => 'Omega moved to Python.'], array_intersect_key($state, array_flip(['state', 'replacement', 'notice'])));
    }

    public function test_the_api_shows_the_lifecycle_and_the_picker_drops_ended_versions(): void
    {
        $admin = User::factory()->create();
        $this->provide('platform-source', $this->lifecycleManifest('zeta'));
        $project = Project::create(['name' => 'dep', 'service_type' => 'xaas', 'runtime' => 'zeta', 'runtime_version' => '2.0']);

        $runtime = collect($this->actingAs($admin, 'sanctum')->getJson('/api/runtimes')->assertOk()->json('data'))->firstWhere('id', 'zeta');
        $versions = collect($runtime['versions'])->keyBy('version');
        $this->assertSame(['eol', 'deprecated', 'active'], $versions->pluck('lifecycle')->values()->all());
        $this->assertSame(now()->addDays(20)->toDateString(), $versions['2.0']['sunset_at']);
        $this->assertSame(now()->addDays(20)->toDateString(), $versions['2.0']['ends_at']);
        $this->assertSame('active', $runtime['lifecycle']['state']);
        $this->assertTrue($runtime['managed']);

        $images = collect($this->actingAs($admin, 'sanctum')->getJson('/api/settings')->assertOk()->json('executor_images'))->where('runtime', 'zeta')->keyBy('version');
        $this->assertSame(now()->addDays(20)->toDateString(), $images['2.0']['ends_at']);
        $this->assertNotNull($images['1.0']['ends_at']);

        $this->actingAs($admin, 'sanctum')->getJson("/api/projects/{$project->id}")->assertOk()
            ->assertJsonPath('data.runtime_lifecycle.state', 'deprecated')
            ->assertJsonPath('data.runtime_lifecycle.ends_by', 'sunset')
            ->assertJsonPath('data.runtime_lifecycle.ends_at', now()->addDays(20)->toDateString());
    }

    public function test_a_save_on_an_ended_version_is_refused(): void
    {
        $admin = User::factory()->create();
        $this->provide('platform-source', $this->lifecycleManifest('zeta'));
        [$project] = $this->runtimeProject('zeta');
        $project->forceFill(['runtime_version' => '1.0'])->save();

        $this->actingAs($admin, 'sanctum')->postJson("/api/projects/{$project->id}/code", ['code' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', __('api.project.eol_save'));
    }

    public function test_deprecations_are_announced_once(): void
    {
        $owner = $this->userWithRole('owner');
        Project::create(['name' => 'dep', 'service_type' => 'xaas', 'runtime' => 'zeta', 'runtime_version' => '2.0'])->forceFill(['owner_id' => $owner->id])->save();
        $this->provide('platform-source', $this->lifecycleManifest('zeta'));
        $this->provide('platform-source', $this->lifecycleManifest('zeta', ['name' => 'Zeta!']));

        $notices = Notification::query()->where('type', 'runtime_eol')->where('data->event', 'deprecated')->where('user_id', $owner->id)->get();
        $this->assertCount(1, $notices);
        $this->assertSame('2.0', $notices->first()->data['version']);
    }

    public function test_discovered_versions_take_the_lifecycle_of_the_manifest(): void
    {
        $this->registry['/v2/runtimes/exec-zig/tags/list'] = ['status' => 200, 'body' => json_encode(['tags' => ['0.13', '0.14', 'latest']]), 'headers' => []];
        $manifest = $this->lifecycleManifest('zig', [
            'versionDiscovery' => [
                'repository' => 'registry.acme.test/runtimes/exec-zig',
                'image' => 'registry.acme.test/runtimes/exec-zig:{version}',
                'lifecycle' => [
                    '0.13' => ['eol' => '2026-01-31', 'sunset' => '2026-01-31'],
                    '0.14' => ['deprecated' => true],
                ],
            ],
        ]);
        unset($manifest['versions']);

        $this->provide('platform-source', $manifest);

        $this->assertSame(['0.13', '0.14'], ExecutorImage::query()->where('runtime', 'zig')->orderBy('sort_order')->pluck('version')->all());
        $this->assertSame('2026-01-31', $this->version('zig', '0.13')->eol_at->toDateString());
        $this->assertTrue($this->version('zig', '0.14')->isDeprecated());
        $this->assertTrue((bool) $this->version('zig', '0.14')->is_default);
    }

    public function test_a_version_removed_from_the_manifest_moves_its_projects_to_the_default(): void
    {
        $this->provide('platform-source', $this->lifecycleManifest('zeta'));
        $manifest = $this->lifecycleManifest('zeta');
        $manifest['versions'] = array_values(array_filter($manifest['versions'], fn ($v) => $v['version'] !== '2.0'));
        $this->provide('platform-source', $manifest);

        $this->assertFalse((bool) $this->version('zeta', '2.0')->is_active);
        $this->assertSame('3.0', ExecutorImage::resolve('zeta', '2.0')->version, 'a retired version runs on the default');
    }
}
