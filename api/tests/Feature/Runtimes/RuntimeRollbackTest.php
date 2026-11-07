<?php

namespace Tests\Feature\Runtimes;

use App\Models\ExecutorImage;
use App\Models\Project;
use App\Models\Runtime;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\ManifestValidator;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * What 0.6.8 leaves for a `helm rollback` to 0.6.7 on the same database (docs/upgrade.md#back-to-067).
 *
 * 0.6.7 looks a runtime up the way RuntimeRegistry::manifest() still does: its own bundled catalog
 * first, then the manifest on the runtime's row. Its catalog has no Java, .NET, Ruby or Bun, so their
 * projects deploy there only from the row: syncBuiltins() puts the catalog's manifest on every
 * built-in row, also while the bundled catalog is in use (RunR's catalog writes it when it is read).
 * The migration stays: its columns are nullable, so 0.6.7's writes need none of them, and its down()
 * is complete in case an operator runs it.
 */
class RuntimeRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_07_100001_create_runtime_sources';

    /** The runtimes of 0.6.8's catalog that 0.6.7's resources/runtimes/builtin.json does not have */
    private const NEW_IN_068 = ['java', 'dotnet', 'ruby', 'bun'];

    protected function setUp(): void
    {
        parent::setUp();
        BuiltinCatalog::flush();
    }

    protected function tearDown(): void
    {
        BuiltinCatalog::flush();
        parent::tearDown();
    }

    /** A registry over 0.6.7's catalog: the bundle without the runtimes 0.6.8 added. */
    private function registryOf067(): RuntimeRegistry
    {
        $earlier = array_diff_key(app(BuiltinCatalog::class)->bundled(), array_flip(self::NEW_IN_068));
        $catalog = new class(app(ManifestValidator::class), $earlier) extends BuiltinCatalog
        {
            /** @param  array<string, RuntimeManifest>  $manifests */
            public function __construct(ManifestValidator $validator, private readonly array $manifests)
            {
                parent::__construct($validator);
            }

            public function all(): array
            {
                return $this->manifests;
            }

            public function bundled(): array
            {
                return $this->manifests;
            }
        };

        return new RuntimeRegistry($catalog, app(ManifestValidator::class));
    }

    public function test_every_built_in_row_carries_the_manifest_of_the_bundled_catalog(): void
    {
        $catalog = app(BuiltinCatalog::class);
        $this->assertSame(BuiltinCatalog::MODE_BUNDLE, $catalog->mode());

        app(RuntimeRegistry::class)->syncBuiltins();

        foreach ($catalog->all() as $id => $manifest) {
            $row = Runtime::query()->findOrFail($id);
            $this->assertSame(Runtime::SOURCE_BUILTIN, $row->source, $id);
            $this->assertNull($row->source_id, $id);
            $this->assertEquals($manifest->toArray(), $row->manifest, "{$id}: the row's manifest is the catalog's");
        }
        // the rows the first migration created without a manifest get it as well
        $this->assertEquals($catalog->get('python')?->toArray(), Runtime::query()->findOrFail('python')->manifest);
        foreach (self::NEW_IN_068 as $id) {
            $this->assertNotNull(Runtime::query()->findOrFail($id)->manifest, $id);
        }
    }

    public function test_the_manifest_follows_the_bundle_and_an_unchanged_one_is_not_written_again(): void
    {
        $registry = app(RuntimeRegistry::class);
        $registry->syncBuiltins();
        $java = Runtime::query()->findOrFail('java');
        $written = $java->updated_at;

        $this->travel(5)->minutes();
        $registry->syncBuiltins();
        $this->assertEquals($written, $java->fresh()->updated_at, 'an unchanged manifest is not written again');

        // a row of an earlier bundle: the bundle's manifest replaces it
        $java->forceFill(['manifest' => array_replace($java->manifest, ['description' => 'an earlier bundle'])])->save();
        $registry->syncBuiltins();
        $this->assertEquals(app(BuiltinCatalog::class)->get('java')?->toArray(), $java->fresh()->manifest);
    }

    public function test_rows_of_runrs_catalog_keep_what_its_sync_wrote(): void
    {
        $fromCatalog = array_replace(app(BuiltinCatalog::class)->get('ruby')?->toArray() ?? [], ['description' => 'as RunR\'s catalog has it']);
        Runtime::query()->create(['id' => 'ruby', 'name' => 'Ruby', 'source' => Runtime::SOURCE_BUILTIN, 'is_enabled' => false, 'manifest' => $fromCatalog])
            ->forceFill(['source_id' => '01JRUNRCATALOGSOURCE000000'])->save();

        app(RuntimeRegistry::class)->syncBuiltins();

        $ruby = Runtime::query()->findOrFail('ruby');
        $this->assertSame('as RunR\'s catalog has it', $ruby->manifest['description']);
        $this->assertFalse($ruby->is_enabled, 'the row keeps its state');
    }

    public function test_an_earlier_catalog_without_the_runtime_deploys_it_from_its_row(): void
    {
        app(RuntimeRegistry::class)->syncBuiltins();
        ExecutorImage::query()->create(['runtime' => 'java', 'version' => '25', 'docker_image' => 'ghcr.io/brf-tech/runr/exec-java:25', 'is_default' => true, 'is_active' => true]);
        $project = Project::query()->create(['name' => 'orders', 'service_type' => 'xaas', 'runtime' => 'java']);

        $earlier = $this->registryOf067();
        foreach (self::NEW_IN_068 as $id) {
            $this->assertNotContains($id, $earlier->builtinIds(), "{$id} is not in 0.6.7's catalog");
        }

        $manifest = $earlier->forProject($project);
        $this->assertSame('java', $manifest->id());
        $this->assertNotNull($manifest->build(), 'the build section of the row (compiled)');
        $this->assertNull($earlier->deployBlockedReason('java', null), 'its projects deploy');
        $this->assertSame('25', $earlier->executorFor($project)->version);
        $this->assertNotContains('java', $earlier->visible()->pluck('id')->all(), 'not listed: no new project from the console');
        $this->assertContains('python', $earlier->visible()->pluck('id')->all());
    }

    public function test_without_the_manifest_on_the_row_an_earlier_catalog_refuses_the_deploy(): void
    {
        // a row of 0.6.8 before syncBuiltins() wrote manifests: what 0.6.7 found in a bundle-mode install
        Runtime::query()->create(['id' => 'bun', 'name' => 'TypeScript (Bun)', 'source' => Runtime::SOURCE_BUILTIN, 'is_enabled' => true]);

        $earlier = $this->registryOf067();
        $this->assertNull($earlier->manifest('bun'));
        $this->assertSame(__('api.runtimes.unknown', ['id' => 'bun']), $earlier->deployBlockedReason('bun', null));
    }

    public function test_the_new_columns_are_nullable_so_writes_of_0_6_7_need_none_of_them(): void
    {
        $added = [
            'runtimes' => ['source_id', 'source_path', 'orphaned_at'],
            'executor_images' => ['deprecated_at', 'sunset_at'],
            'runtime_patches' => ['source_id'],
            'runtime_patch_feeds' => ['source_id'],
        ];
        foreach ($added as $table => $columns) {
            $byName = collect(Schema::getColumns($table))->keyBy('name');
            foreach ($columns as $column) {
                $this->assertTrue($byName->has($column), "{$table}.{$column}");
                $this->assertTrue((bool) $byName[$column]['nullable'], "{$table}.{$column} is nullable");
                $this->assertNull($byName[$column]['default'], "{$table}.{$column} has no default");
            }
        }

        // a version and a runtime as 0.6.7 writes them
        ExecutorImage::query()->create(['runtime' => 'python', 'version' => '3.13', 'docker_image' => 'ghcr.io/brf-tech/runr/exec-python:3.13', 'is_active' => true]);
        Runtime::query()->create(['id' => 'lua', 'name' => 'Lua', 'source' => Runtime::SOURCE_PLATFORM, 'is_enabled' => true]);
        $this->assertNull(Runtime::query()->findOrFail('lua')->source_id);
    }

    public function test_the_migration_rolls_back_completely_and_runs_again(): void
    {
        $path = 'database/migrations/'.self::MIGRATION.'.php';

        // the command of the upgrade notes, with the 0.6.8 image: only this migration goes back
        $this->artisan('migrate:rollback', ['--path' => $path, '--force' => true])->assertSuccessful();

        $this->assertFalse(DB::table('migrations')->where('migration', self::MIGRATION)->exists());
        $this->assertTrue(DB::table('migrations')->where('migration', '2026_11_04_100001_create_notification_user_states_table')->exists(), 'the earlier migrations stay');
        $this->assertFalse(Schema::hasTable('runtime_sources'));
        foreach (['source_id', 'source_path', 'orphaned_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('runtimes', $column), "runtimes.{$column}");
        }
        $this->assertFalse(Schema::hasColumn('executor_images', 'deprecated_at'));
        $this->assertFalse(Schema::hasColumn('executor_images', 'sunset_at'));
        $this->assertFalse(Schema::hasColumn('runtime_patches', 'source_id'));
        $this->assertFalse(Schema::hasColumn('runtime_patch_feeds', 'source_id'));
        $this->assertSame([], collect(Schema::getIndexes('runtimes'))->filter(fn (array $i) => in_array('source_id', $i['columns'], true))->all());

        // the next upgrade runs it again
        $this->artisan('migrate', ['--path' => $path, '--force' => true])->assertSuccessful();

        $this->assertTrue(DB::table('migrations')->where('migration', self::MIGRATION)->exists());
        $this->assertTrue(Schema::hasTable('runtime_sources'));
        $this->assertTrue(Schema::hasColumns('runtimes', ['source_id', 'source_path', 'orphaned_at']));
        $this->assertTrue(Schema::hasColumns('executor_images', ['deprecated_at', 'sunset_at']));
        $this->assertTrue(Schema::hasColumn('runtime_patches', 'source_id'));
        $this->assertTrue(Schema::hasColumn('runtime_patch_feeds', 'source_id'));
    }

    public function test_runtimes_sync_leaves_the_discovered_versions_of_a_source_runtime_alone(): void
    {
        $raw = collect(json_decode((string) file_get_contents(BuiltinCatalog::bundlePath()), true)['runtimes'])->firstWhere('id', 'java');
        $manifest = app(ManifestValidator::class)->validate(array_replace($raw, ['id' => 'acme-java', 'name' => 'Acme Java']), [], true);
        $this->assertNotNull($manifest->versionDiscovery());
        Runtime::query()->create(['id' => 'acme-java', 'name' => 'Acme Java', 'source' => Runtime::SOURCE_PLATFORM, 'is_enabled' => true, 'manifest' => $manifest->toArray(), 'digest' => $manifest->digest()])
            ->forceFill(['source_id' => '01JACMESOURCE0000000000000'])->save();
        ExecutorImage::query()->create(['runtime' => 'acme-java', 'version' => '25', 'docker_image' => 'registry.acme.test/exec-java:25', 'is_default' => true, 'is_active' => true]);

        $this->artisan('runtimes:sync')->assertSuccessful();

        $version = ExecutorImage::query()->where('runtime', 'acme-java')->sole();
        $this->assertTrue($version->is_active, 'a discovered version is not retired for a versions list the manifest does not have');
        $this->assertTrue($version->is_default);
    }
}
