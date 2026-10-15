<?php

namespace Tests\Feature\Images;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Version;
use App\Services\Runtimes\RuntimeImageBuildService;
use App\Support\Secrets\SecretCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * RunR's image namespace (0.5.1): the upgrade migrations (central and tenant schema) and
 * `php artisan images:move-registry` move every stored image reference under ghcr.io/runrsh/ to
 * ghcr.io/brf-tech/ — exact prefix only, references into other registries stay. Runs on SQLite
 * and on PostgreSQL (DB_CONNECTION=pgsql: json columns, tenant schemas next to "public").
 */
class ImageRegistryMoveTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private const MIGRATION = 'database/migrations/2026_10_16_000001_move_images_to_brf_tech_registry.php';

    private const TENANT_MIGRATION = 'Modules/Tenancy/Database/TenantMigrations/0054_move_images_to_brf_tech_registry.php';

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function migrate(string $migration = self::MIGRATION): void
    {
        (require base_path($migration))->up();
    }

    private function now(): array
    {
        return ['created_at' => now(), 'updated_at' => now()];
    }

    /** A region, a CaaS project with a version and a deployment, in the current schema. @return array<string, string> ids */
    private function seedShared(string $suffix = ''): array
    {
        $region = (string) Str::ulid();
        DB::table('knative_regions')->insert(['id' => $region, 'name' => 'eu-1'.$suffix,
            // JSON as the array cast writes it: escaped slashes
            'knative_settings' => json_encode(['sandbox_image' => 'ghcr.io/runrsh/exec-python:3.14', 'request_timeout' => 30, 'empty' => new \stdClass]),
            'gpu_settings' => json_encode(['device_plugin_image' => 'nvcr.io/nvidia/k8s-device-plugin:v0.17.0']),
        ] + $this->now());

        $project = (string) Str::ulid();
        DB::table('projects')->insert(['id' => $project, 'name' => 'web'.$suffix, 'slug' => 'web'.$suffix, 'service_type' => 'caas', 'knative_region_id' => $region] + $this->now());
        $config = (string) Str::ulid();
        DB::table('container_configs')->insert(['id' => $config, 'project_id' => $project, 'image' => 'ghcr.io/runrsh/reqs/nginx:1.27-alpine'] + $this->now());
        $other = (string) Str::ulid();
        $otherProject = (string) Str::ulid();
        DB::table('projects')->insert(['id' => $otherProject, 'name' => 'own'.$suffix, 'slug' => 'own'.$suffix, 'service_type' => 'caas', 'knative_region_id' => $region] + $this->now());
        DB::table('container_configs')->insert(['id' => $other, 'project_id' => $otherProject, 'image' => 'registry.example.com/runrsh/api:2'] + $this->now());

        $version = (string) Str::ulid();
        DB::table('versions')->insert(['id' => $version, 'project_id' => $project, 'number' => 1, 'created_by' => 'dev',
            'config_snapshot' => SecretCipher::encode(['image' => 'ghcr.io/runrsh/reqs/nginx:1.27-alpine', 'env_vars' => ['TOKEN' => 'snap-secret']], true),
        ] + $this->now());
        $deployment = (string) Str::ulid();
        DB::table('deployments')->insert(['id' => $deployment, 'project_id' => $project, 'version_id' => $version,
            'deploy_config' => json_encode(['image' => 'ghcr.io/runrsh/reqs/nginx:1.27-alpine', 'min_scale' => 0], JSON_UNESCAPED_SLASHES),
        ] + $this->now());

        $service = (string) Str::ulid();
        DB::table('ai_model_services')->insert(['id' => $service, 'ai_model_id' => (string) Str::ulid(), 'knative_region_id' => $region,
            'name' => 'chat'.$suffix, 'service_name' => 'chat'.$suffix, 'engine' => 'custom', 'served_name' => 'chat',
            'image' => 'ghcr.io/runrsh/hf-serve:1.0.0'] + $this->now());

        $binding = (string) Str::ulid();
        DB::table('bindings')->insert(['id' => $binding, 'name' => 'cache'.$suffix, 'resource_name' => 'cache'.$suffix, 'type' => 'kv', 'knative_region_id' => $region,
            'settings' => json_encode(['image' => 'ghcr.io/runrsh/reqs/redis:7-alpine'])] + $this->now());
        $regionService = (string) Str::ulid();
        DB::table('binding_region_services')->insert(['id' => $regionService, 'knative_region_id' => $region, 'service' => 'kv',
            'config' => json_encode(['image' => 'ghcr.io/runrsh/reqs/redis:7-alpine', 'storage' => '2Gi'])] + $this->now());

        $report = (string) Str::ulid();
        DB::table('image_security_reports')->insert(['id' => $report, 'subject_type' => 'version', 'subject_id' => $version,
            'knative_region_id' => $region, 'image_kind' => 'caas', 'image_ref' => 'ghcr.io/runrsh/reqs/nginx:1.27-alpine'] + $this->now());

        return compact('region', 'project', 'config', 'other', 'version', 'deployment', 'service', 'binding', 'regionService', 'report');
    }

    private function value(string $table, string $column, string $id, string $key = 'id'): ?string
    {
        return DB::table($table)->where($key, $id)->value($column);
    }

    /** @param  array<string, string>  $ids */
    private function assertSharedMoved(array $ids): void
    {
        $this->assertSame('ghcr.io/brf-tech/reqs/nginx:1.27-alpine', $this->value('container_configs', 'image', $ids['config']));
        $this->assertSame('registry.example.com/runrsh/api:2', $this->value('container_configs', 'image', $ids['other']), 'another registry stays');

        $snapshot = $this->value('versions', 'config_snapshot', $ids['version']);
        $this->assertTrue(SecretCipher::isEncrypted($snapshot), 'the snapshot stays encrypted');
        $this->assertSame(['image' => 'ghcr.io/brf-tech/reqs/nginx:1.27-alpine', 'env_vars' => ['TOKEN' => 'snap-secret']], SecretCipher::decode($snapshot, true));

        $this->assertSame(['image' => 'ghcr.io/brf-tech/reqs/nginx:1.27-alpine', 'min_scale' => 0], json_decode($this->value('deployments', 'deploy_config', $ids['deployment']), true));

        $settings = $this->value('knative_regions', 'knative_settings', $ids['region']);
        $this->assertSame('ghcr.io/brf-tech/exec-python:3.14', json_decode($settings, true)['sandbox_image']);
        $this->assertSame(30, json_decode($settings, true)['request_timeout']);
        $this->assertInstanceOf(\stdClass::class, json_decode($settings)->empty, 'an empty object stays an object');
        $this->assertStringContainsString('nvcr.io\/nvidia', $this->value('knative_regions', 'gpu_settings', $ids['region']), 'a document without the namespace is not rewritten');

        $this->assertSame('ghcr.io/brf-tech/hf-serve:1.0.0', $this->value('ai_model_services', 'image', $ids['service']));
        $this->assertSame(['image' => 'ghcr.io/brf-tech/reqs/redis:7-alpine'], json_decode($this->value('bindings', 'settings', $ids['binding']), true));
        $this->assertSame(['image' => 'ghcr.io/brf-tech/reqs/redis:7-alpine', 'storage' => '2Gi'], json_decode($this->value('binding_region_services', 'config', $ids['regionService']), true));
        $this->assertSame('ghcr.io/brf-tech/reqs/nginx:1.27-alpine', $this->value('image_security_reports', 'image_ref', $ids['report']));
    }

    public function test_the_migration_moves_every_stored_reference_of_the_central_schema(): void
    {
        $ids = $this->seedShared();
        $images = [
            'ghcr.io/runrsh/exec-python:3.12' => 'ghcr.io/brf-tech/exec-python:3.12',
            'ghcr.io/runrsh/exec-wasm-rust:4.1' => 'ghcr.io/brf-tech/exec-wasm-rust:4.1',
            'docker.io/library/python:3.12-slim' => 'docker.io/library/python:3.12-slim',
            'zot.runr-registry.svc:5000/ghcr.io/runrsh/exec-go:1.26' => 'zot.runr-registry.svc:5000/ghcr.io/runrsh/exec-go:1.26',
        ];
        $executor = [];
        foreach (array_keys($images) as $i => $image) {
            $executor[$image] = (string) Str::ulid();
            DB::table('executor_images')->insert(['id' => $executor[$image], 'runtime' => "r{$i}", 'version' => '1', 'docker_image' => $image] + $this->now());
        }
        $sandbox = (string) Str::ulid();
        DB::table('sandboxes')->insert(['id' => $sandbox, 'knative_region_id' => $ids['region'], 'namespace' => 'runr-sbx-acme', 'pod_name' => 'sbx-1',
            'image' => 'ghcr.io/runrsh/exec-python:3.14', 'cpu_m' => 500, 'memory_mb' => 512, 'disk_mb' => 1024, 'pids_limit' => 256, 'uid' => 1000, 'ttl_seconds' => 600] + $this->now());
        DB::table('region_supply_chain_settings')->insert(['knative_region_id' => $ids['region'],
            'admission_exempt_images' => json_encode(['ghcr.io/runrsh/reqs/**', 'quay.io/acme/**']),
            'caas_allowed_registries' => json_encode(['ghcr.io/runrsh', 'docker.io/library']),
        ] + $this->now());

        $this->migrate();

        $this->assertSharedMoved($ids);
        foreach ($images as $from => $to) {
            $this->assertSame($to, $this->value('executor_images', 'docker_image', $executor[$from]), $from);
        }
        $this->assertSame('ghcr.io/brf-tech/exec-python:3.14', $this->value('sandboxes', 'image', $sandbox));
        $this->assertSame(['ghcr.io/brf-tech/reqs/**', 'quay.io/acme/**'], json_decode($this->value('region_supply_chain_settings', 'admission_exempt_images', $ids['region'], 'knative_region_id'), true));
        $this->assertSame(['ghcr.io/brf-tech', 'docker.io/library'], json_decode($this->value('region_supply_chain_settings', 'caas_allowed_registries', $ids['region'], 'knative_region_id'), true));

        // The models read the moved values
        $this->assertSame('ghcr.io/brf-tech/reqs/nginx:1.27-alpine', Version::find($ids['version'])->config_snapshot['image']);
        $this->assertSame('ghcr.io/brf-tech/reqs/nginx:1.27-alpine', Project::find($ids['project'])->containerConfig->image);
    }

    public function test_runtime_plugins_move_with_their_region_builds(): void
    {
        $dockerfile = "FROM ghcr.io/runrsh/reqs/golang:1.26-alpine AS build\nCOPY . /src\nFROM ghcr.io/runrsh/reqs/alpine:3.21\n";
        $manifest = [
            'id' => 'q', 'name' => 'Q', 'image' => 'ghcr.io/runrsh/reqs/alpine:3.21',
            'versions' => [
                ['version' => '1', 'image' => null, 'dockerfile' => 'Dockerfile', 'git' => null, 'upload' => null, 'buildArgs' => ['BASE' => 'ghcr.io/runrsh/reqs/alpine:3.21'], 'default' => true],
                ['version' => '2', 'image' => 'ghcr.io/runrsh/reqs/alpine:3.20', 'dockerfile' => null, 'git' => null, 'upload' => null, 'buildArgs' => [], 'default' => false],
            ],
            'build' => ['image' => '{image}', 'runImage' => 'ghcr.io/runrsh/reqs/alpine:3.21'],
        ];
        $source = "id: q\nname: Q\nversions:\n  - version: '1'\n    dockerfile: Dockerfile\n    buildArgs: {BASE: ghcr.io/runrsh/reqs/alpine:3.21}\n  - version: '2'\n    image: ghcr.io/runrsh/reqs/alpine:3.20\nbuild:\n  runImage: ghcr.io/runrsh/reqs/alpine:3.21\n";
        DB::table('runtimes')->insert(['id' => 'q', 'name' => 'Q', 'source' => 'platform', 'manifest' => json_encode($manifest), 'manifest_source' => $source,
            'files' => json_encode(['Dockerfile' => $dockerfile]), 'digest' => str_repeat('0', 64), 'is_enabled' => true] + $this->now());
        DB::table('runtimes')->insert(['id' => 'r', 'name' => 'R', 'source' => 'custom', 'manifest' => json_encode(['id' => 'r', 'versions' => [['version' => '1', 'image' => 'docker.io/library/ruby:3']]]),
            'manifest_source' => "id: r\n", 'digest' => 'keep', 'is_enabled' => true] + $this->now());

        $service = app(RuntimeImageBuildService::class);
        $old = (new Runtime)->forceFill(['id' => 'q', 'files' => ['Dockerfile' => $dockerfile]]);
        $built = $service->digest($old, $manifest['versions'][0]);
        $region = (string) Str::ulid();
        DB::table('runtime_builds')->insert(['id' => (string) Str::ulid(), 'runtime_id' => 'q', 'version' => '1', 'knative_region_id' => $region,
            'status' => 'ready', 'image' => 'zot:5000/runr-runtimes/q:1-'.substr($built, 0, 12), 'digest' => $built] + $this->now());
        DB::table('runtime_builds')->insert(['id' => (string) Str::ulid(), 'runtime_id' => 'q', 'version' => '1', 'knative_region_id' => (string) Str::ulid(),
            'status' => 'failed', 'digest' => 'an-older-source'] + $this->now());

        $this->migrate();

        $row = DB::table('runtimes')->where('id', 'q')->first();
        $moved = json_decode($row->manifest, true);
        $this->assertSame('ghcr.io/brf-tech/reqs/alpine:3.21', $moved['image']);
        $this->assertSame('ghcr.io/brf-tech/reqs/alpine:3.21', $moved['versions'][0]['buildArgs']['BASE']);
        $this->assertSame('ghcr.io/brf-tech/reqs/alpine:3.20', $moved['versions'][1]['image']);
        $this->assertSame('ghcr.io/brf-tech/reqs/alpine:3.21', $moved['build']['runImage']);
        $this->assertStringNotContainsString('runrsh', $row->manifest_source);
        $this->assertStringContainsString('runImage: ghcr.io/brf-tech/reqs/alpine:3.21', $row->manifest_source);
        $this->assertSame(['Dockerfile' => "FROM ghcr.io/brf-tech/reqs/golang:1.26-alpine AS build\nCOPY . /src\nFROM ghcr.io/brf-tech/reqs/alpine:3.21\n"], json_decode($row->files, true));
        $this->assertSame(hash('sha256', json_encode($moved, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $row->digest);

        // The region build of the unchanged source still counts as built: its digest is the new source's
        $runtime = Runtime::find('q');
        $this->assertNotNull($service->readyBuild($runtime, $moved['versions'][0], KnativeRegion::make()->forceFill(['id' => $region])));
        $this->assertSame(1, DB::table('runtime_builds')->where('digest', 'an-older-source')->count(), 'a build of another source stays');

        // A runtime without the namespace is not touched
        $this->assertSame('keep', DB::table('runtimes')->where('id', 'r')->value('digest'));
    }

    public function test_the_move_is_idempotent_and_the_command_reports_it(): void
    {
        $ids = $this->seedShared();
        DB::table('executor_images')->insert(['id' => (string) Str::ulid(), 'runtime' => 'python', 'version' => '3.12', 'docker_image' => 'ghcr.io/runrsh/exec-python:3.12'] + $this->now());

        $this->artisan('images:move-registry', ['--dry-run' => true])
            ->expectsOutput('  central: container_configs.image 1, versions.config_snapshot 1, deployments.deploy_config 1, '
                .'knative_regions.knative_settings 1, ai_model_services.image 1, bindings.settings 1, binding_region_services.config 1, '
                .'image_security_reports.image_ref 1, executor_images.docker_image 1')
            ->expectsOutput('Would move 9 stored image reference(s) to ghcr.io/brf-tech.')
            ->assertSuccessful();
        $this->assertSame('ghcr.io/runrsh/reqs/nginx:1.27-alpine', $this->value('container_configs', 'image', $ids['config']), 'dry run: nothing written');

        $this->artisan('images:move-registry')->expectsOutput('Moved 9 stored image reference(s) to ghcr.io/brf-tech.')->assertSuccessful();
        $this->assertSharedMoved($ids);

        $this->artisan('images:move-registry')
            ->expectsOutput('  central: nothing to move')
            ->expectsOutput('Moved 0 stored image reference(s) to ghcr.io/brf-tech.')
            ->assertSuccessful();
        $this->migrate();
        $this->assertSharedMoved($ids);
    }

    public function test_the_tenant_migration_moves_the_tenant_schema_only(): void
    {
        $this->setUpTenancy();
        $central = $this->seedShared('-central');

        $tenant = $this->createTenantWithSchema('acme', array_merge(self::TENANT_REGION_MIGRATIONS, [
            'Modules/Tenancy/Database/TenantMigrations/0035_create_bindings_tables.php',
            'Modules/Tenancy/Database/TenantMigrations/0035_create_supply_chain_report_tables.php',
            'Modules/Tenancy/Database/TenantMigrations/0041_encrypt_secret_columns.php',
            'Modules/Tenancy/Database/TenantMigrations/0046_create_ai_model_tables.php',
        ]));

        $tenant->run(function () {
            $ids = $this->seedShared('-tenant');

            $this->migrate(self::TENANT_MIGRATION);

            $this->assertSharedMoved($ids);
        });

        // the central schema is the central migration's
        $this->assertSame('ghcr.io/runrsh/reqs/nginx:1.27-alpine', $this->value('container_configs', 'image', $central['config']));
        $this->assertSame('ghcr.io/runrsh/hf-serve:1.0.0', $this->value('ai_model_services', 'image', $central['service']));

        // the command covers both
        config(['tenancy.enabled' => true]);
        $this->artisan('images:move-registry')->expectsOutputToContain('tenant acme: nothing to move')->assertSuccessful();
        $this->assertSharedMoved($central);
    }
}
