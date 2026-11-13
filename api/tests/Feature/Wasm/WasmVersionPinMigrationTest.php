<?php

namespace Tests\Feature\Wasm;

use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Upgrade to 0.7.0: wasm-rust and wasm-js default to 4.2 for new projects, so the migrations
 * (central schema and every tenant schema) pin their existing projects without a runtime version
 * to 4.1 — the next deploy of such a project builds on the version its code was written for.
 * Pinned projects, other runtimes and wasm-go (still 4.1 only) are left as they are.
 */
class WasmVersionPinMigrationTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase, WiresTenancy;

    private const MIGRATION = 'database/migrations/2026_11_12_200001_pin_versionless_wasm_projects_to_4_1.php';

    private const TENANT_MIGRATION = 'Modules/Tenancy/Database/TenantMigrations/0113_pin_versionless_wasm_projects_to_4_1.php';

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    /** @return array<string, string> name => project id */
    private function seedProjects(string $suffix = ''): array
    {
        $ids = [];
        foreach ([
            'rust' => ['wasm-rust', null],
            'rust-empty' => ['wasm-rust', ''],
            'js' => ['wasm-js', null],
            'rust-pinned' => ['wasm-rust', '4.2'],
            'js-pinned' => ['wasm-js', '4.1'],
            'go' => ['wasm-go', null],
            'python' => ['python', null],
        ] as $name => [$runtime, $version]) {
            $ids[$name] = (string) Str::ulid();
            DB::table('projects')->insert([
                'id' => $ids[$name], 'name' => $name.$suffix, 'slug' => $name.$suffix, 'service_type' => 'xaas',
                'runtime' => $runtime, 'runtime_version' => $version, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /** @param  array<string, string>  $ids */
    private function assertPinned(array $ids): void
    {
        $versions = DB::table('projects')->whereIn('id', $ids)->pluck('runtime_version', 'id');
        $this->assertSame([
            'rust' => '4.1', 'rust-empty' => '4.1', 'js' => '4.1',
            'rust-pinned' => '4.2', 'js-pinned' => '4.1', 'go' => null, 'python' => null,
        ], array_map(fn ($id) => $versions[$id], $ids));
    }

    public function test_versionless_wasm_rust_and_wasm_js_projects_are_pinned_to_4_1(): void
    {
        $ids = $this->seedProjects();

        (require base_path(self::MIGRATION))->up();
        $this->assertPinned($ids);

        // idempotent
        (require base_path(self::MIGRATION))->up();
        $this->assertPinned($ids);
    }

    public function test_the_tenant_migration_pins_the_tenant_schemas_projects(): void
    {
        $this->setUpTenancy();
        $central = $this->seedProjects('-central');

        $tenant = $this->createTenantWithSchema('acme');
        $tenant->run(function () {
            $ids = $this->seedProjects('-tenant');

            (require base_path(self::TENANT_MIGRATION))->up();

            $this->assertPinned($ids);
        });

        // the central schema is the central migration's
        $this->assertNull(DB::table('projects')->where('id', $central['rust'])->value('runtime_version'));
    }

    public function test_a_pinned_project_moves_to_4_2_by_its_runtime_version(): void
    {
        $owner = $this->userWithRole('owner');
        $region = KnativeRegion::create(['name' => 'eu-1', 'is_active' => true, 'is_demo' => true]);
        foreach (['4.1' => false, '4.2' => true] as $version => $default) {
            ExecutorImage::create(['runtime' => 'wasm-rust', 'version' => $version, 'docker_image' => "ghcr.io/brf-tech/runr/exec-wasm-rust:{$version}", 'is_default' => $default, 'is_active' => true, 'sort_order' => 1]);
        }
        $project = Project::create(['name' => 'hello', 'service_type' => 'xaas', 'runtime' => 'wasm-rust', 'runtime_version' => '4.1']);
        $project->forceFill(['knative_region_id' => $region->id, 'runtime_patch_pin' => 'wasm-rust-4.1-r1'])->saveQuietly();

        // after porting the code: the next build and deploy run 4.2; a patch pin of 4.1 goes
        $this->signedIn($owner)->putJson("/api/projects/{$project->id}", ['runtime_version' => '4.2'])->assertOk();
        $this->assertSame(['4.2', null], [$project->fresh()->runtime_version, $project->fresh()->runtime_patch_pin]);

        // a version the runtime does not have is refused; null follows the runtime's default
        $this->signedIn($owner)->putJson("/api/projects/{$project->id}", ['runtime_version' => '9.9'])->assertUnprocessable()->assertJsonValidationErrors('runtime_version');
        $this->signedIn($owner)->putJson("/api/projects/{$project->id}", ['runtime_version' => null])->assertOk();
        $this->assertNull($project->fresh()->runtime_version);
    }

    public function test_both_migrations_pin_the_same_runtimes_to_the_same_version(): void
    {
        $central = require base_path(self::MIGRATION);
        $tenant = require base_path(self::TENANT_MIGRATION);

        $this->assertSame($central::RUNTIMES, $tenant::RUNTIMES);
        $this->assertSame($central::VERSION, $tenant::VERSION);
    }
}
