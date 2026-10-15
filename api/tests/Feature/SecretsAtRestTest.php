<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\Version;
use App\Support\Secrets\SecretCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesEncryptionKeys;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Encryption at rest (docs/security.md): model casts, the data migrations that encrypt rows
 * written before (central + tenant schema), idempotency and key rotation.
 */
class SecretsAtRestTest extends TestCase
{
    use RefreshDatabase, UsesEncryptionKeys, WiresTenancy;

    private const KUBECONFIG = "apiVersion: v1\nkind: Config\ncurrent-context: c\nclusters:\n- name: k\n  cluster:\n    server: https://k8s.example.test:6443\ncontexts:\n- name: c\n  context: {cluster: k, user: u}\nusers:\n- name: u\n  user: {token: abc}\n";

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function raw(string $table, string $column, string $id): ?string
    {
        return DB::table($table)->where('id', $id)->value($column);
    }

    private function project(string $name = 'svc'): Project
    {
        return Project::create(['name' => $name, 'service_type' => 'faas']);
    }

    public function test_env_vars_are_encrypted_in_the_database(): void
    {
        $config = FaasConfig::create([
            'project_id' => $this->project()->id,
            'handler' => 'main.handler',
            'env_vars' => ['API_URL' => 'https://api.test', 'TOKEN' => 'tok-very-secret'],
        ]);

        $raw = $this->raw('faas_configs', 'env_vars', $config->id);
        $this->assertTrue(SecretCipher::isEncrypted($raw));
        $this->assertStringNotContainsString('tok-very-secret', $raw);
        $this->assertSame(['API_URL' => 'https://api.test', 'TOKEN' => 'tok-very-secret'], $config->fresh()->env_vars);
    }

    public function test_assigning_the_same_value_does_not_make_the_column_dirty(): void
    {
        $config = FaasConfig::create(['project_id' => $this->project()->id, 'handler' => 'main.handler', 'env_vars' => ['A' => '1']])->fresh();

        $config->env_vars = ['A' => '1'];
        $this->assertFalse($config->isDirty('env_vars'));

        $config->env_vars = ['A' => '2'];
        $this->assertTrue($config->isDirty('env_vars'));
    }

    public function test_region_secrets_are_encrypted_and_never_serialized(): void
    {
        $region = KnativeRegion::create([
            'name' => 'r1',
            'kubeconfig' => self::KUBECONFIG,
            'redis_password' => 'redis-pw',
            'registry_password' => 'reg-pw',
            'registry_storage_config' => ['endpoint' => 'https://s3.test', 'access_key' => 'AKIA1', 'secret_key' => 'SK-secret'],
        ]);

        foreach (['kubeconfig', 'redis_password', 'registry_password', 'registry_storage_config'] as $column) {
            $raw = $this->raw('knative_regions', $column, $region->id);
            $this->assertTrue(SecretCipher::isEncrypted($raw), "{$column} is encrypted");
        }
        $this->assertStringNotContainsString('SK-secret', $this->raw('knative_regions', 'registry_storage_config', $region->id));

        $fresh = KnativeRegion::find($region->id);
        $this->assertSame(self::KUBECONFIG, $fresh->kubeconfig);
        $this->assertSame('https://k8s.example.test:6443', $fresh->server_url);
        $this->assertSame('SK-secret', $fresh->registry_storage_config['secret_key']);

        $array = $fresh->toArray();
        foreach (['kubeconfig', 'redis_password', 'registry_password', 'registry_storage_config', 'migration_old_kubeconfig'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $array);
        }
        $this->assertStringNotContainsString('SK-secret', json_encode($array));
    }

    public function test_version_snapshots_are_encrypted(): void
    {
        $version = Version::create([
            'project_id' => $this->project()->id,
            'number' => 1,
            'created_by' => 'dev',
            'config_snapshot' => ['env_vars' => ['DB_PASS' => 'snap-secret'], 'trigger_type' => 'http'],
        ]);

        $this->assertStringNotContainsString('snap-secret', $this->raw('versions', 'config_snapshot', $version->id));
        $this->assertSame('http', $version->fresh()->config_snapshot['trigger_type']);
    }

    public function test_legacy_plaintext_rows_stay_readable(): void
    {
        $project = $this->project();
        $id = (string) Str::ulid();
        DB::table('faas_configs')->insert([
            'id' => $id, 'project_id' => $project->id, 'handler' => 'main.handler',
            'env_vars' => json_encode(['LEGACY' => 'plain']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(['LEGACY' => 'plain'], FaasConfig::find($id)->env_vars);
    }

    public function test_the_migration_encrypts_existing_plaintext_rows_and_is_idempotent(): void
    {
        $project = $this->project();
        $configId = (string) Str::ulid();
        DB::table('faas_configs')->insert([
            'id' => $configId, 'project_id' => $project->id, 'handler' => 'main.handler',
            'env_vars' => json_encode(['PW' => 'old-plain']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $regionId = (string) Str::ulid();
        DB::table('knative_regions')->insert([
            'id' => $regionId, 'name' => 'legacy', 'kubeconfig' => self::KUBECONFIG,
            'registry_storage_config' => json_encode(['secret_key' => 'legacy-sk']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $secretPw = Crypt::encryptString('already-encrypted');
        DB::table('knative_regions')->where('id', $regionId)->update(['redis_password' => $secretPw]);

        $migration = require base_path('database/migrations/2026_10_10_000002_encrypt_secret_columns.php');
        $migration->up();

        $env = $this->raw('faas_configs', 'env_vars', $configId);
        $kube = $this->raw('knative_regions', 'kubeconfig', $regionId);
        $this->assertTrue(SecretCipher::isEncrypted($env));
        $this->assertTrue(SecretCipher::isEncrypted($kube));
        $this->assertStringNotContainsString('legacy-sk', $this->raw('knative_regions', 'registry_storage_config', $regionId));
        $this->assertSame($secretPw, $this->raw('knative_regions', 'redis_password', $regionId), 'an encrypted value is left as it is');

        $this->assertSame(['PW' => 'old-plain'], FaasConfig::find($configId)->env_vars);
        $this->assertSame(self::KUBECONFIG, KnativeRegion::find($regionId)->kubeconfig);
        $this->assertSame('already-encrypted', KnativeRegion::find($regionId)->redis_password);

        // Second run: nothing changes
        $migration->up();
        $this->assertSame($env, $this->raw('faas_configs', 'env_vars', $configId));
        $this->assertSame($kube, $this->raw('knative_regions', 'kubeconfig', $regionId));
    }

    public function test_secrets_encrypt_rotate_re_encrypts_with_the_new_key(): void
    {
        $old = self::newKey();
        $this->useKeys($old);
        $config = FaasConfig::create(['project_id' => $this->project()->id, 'handler' => 'main.handler', 'env_vars' => ['K' => 'v']]);
        $secret = ProjectSecret::create(['project_id' => $config->project_id, 'name' => 'S', 'value' => 'secret-v']);

        $new = self::newKey();
        $this->useKeys($new, [$old]);

        $this->artisan('secrets:encrypt', ['--rotate' => true])->assertSuccessful();

        $this->assertSame(['K' => 'v'], json_decode($this->encrypterFor($new)->decryptString($this->raw('faas_configs', 'env_vars', $config->id)), true));
        $this->assertSame('secret-v', $this->encrypterFor($new)->decryptString($this->raw('project_secrets', 'value', $secret->id)));

        $event = AuditEvent::where('action', 'secrets.reencrypted')->latest('occurred_at')->first();
        $this->assertNotNull($event);
        $this->assertSame('system', $event->actor_type);
        $this->assertTrue($event->metadata['rotate']);
        $this->assertGreaterThanOrEqual(2, $event->metadata['rotated']);
    }

    public function test_a_synced_region_copy_keeps_the_raw_payload_instead_of_encrypting_it_again(): void
    {
        $payload = Crypt::encryptString('redis-pw');

        // TenantLocalRegion::create($central->getAttributes()) — raw column values through the cast
        $copy = KnativeRegion::create(['name' => 'copy', 'redis_password' => $payload]);
        $this->assertSame($payload, $this->raw('knative_regions', 'redis_password', $copy->id));
        $this->assertSame('redis-pw', $copy->fresh()->redis_password);

        // A copy written before the fix (encrypted twice) reads the value too
        DB::table('knative_regions')->where('id', $copy->id)->update(['redis_password' => Crypt::encryptString($payload)]);
        $this->assertSame('redis-pw', KnativeRegion::find($copy->id)->redis_password);
    }

    public function test_the_tenant_migration_encrypts_the_tenant_schema_only(): void
    {
        $this->setUpTenancy();

        // Central row: must not be touched by the tenant migration
        $centralProject = $this->project('central-svc');
        $centralId = (string) Str::ulid();
        DB::table('faas_configs')->insert([
            'id' => $centralId, 'project_id' => $centralProject->id, 'handler' => 'main.handler',
            'env_vars' => json_encode(['CENTRAL' => 'plain']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $tenant = $this->createTenantWithSchema('acme', [
            'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
            'Modules/Tenancy/Database/TenantMigrations/0002_create_knative_regions_table.php',
            'Modules/Tenancy/Database/TenantMigrations/0005_add_migration_fields_to_knative_regions.php',
            'Modules/Tenancy/Database/TenantMigrations/0040_create_project_secrets_table.php',
        ]);

        $tenant->run(function () {
            $projectId = (string) Str::ulid();
            DB::table('projects')->insert([
                'id' => $projectId, 'name' => 'svc', 'slug' => 'svc-acme', 'service_type' => 'faas',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('faas_configs')->insert([
                'id' => (string) Str::ulid(), 'project_id' => $projectId, 'handler' => 'main.handler',
                'env_vars' => json_encode(['TENANT' => 'plain-tenant']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('knative_regions')->insert([
                'id' => (string) Str::ulid(), 'name' => 'own', 'kubeconfig' => self::KUBECONFIG,
                'migration_old_kubeconfig' => self::KUBECONFIG, 'created_at' => now(), 'updated_at' => now(),
            ]);

            (require base_path('Modules/Tenancy/Database/TenantMigrations/0041_encrypt_secret_columns.php'))->up();

            $this->assertTrue(SecretCipher::isEncrypted(DB::table('faas_configs')->value('env_vars')));
            $this->assertTrue(SecretCipher::isEncrypted(DB::table('knative_regions')->value('kubeconfig')));
            $this->assertTrue(SecretCipher::isEncrypted(DB::table('knative_regions')->value('migration_old_kubeconfig')));
            $this->assertSame(['TENANT' => 'plain-tenant'], FaasConfig::first()->env_vars);
        });

        $this->assertSame(json_encode(['CENTRAL' => 'plain']), $this->raw('faas_configs', 'env_vars', $centralId));
    }
}
