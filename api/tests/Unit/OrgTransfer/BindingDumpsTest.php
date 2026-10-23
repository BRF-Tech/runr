<?php

namespace Tests\Unit\OrgTransfer;

use App\Models\Binding;
use App\Models\KnativeRegion;
use App\Services\Bindings\BindingDrivers;
use App\Services\Bindings\DataServices\KvService;
use App\Services\Bindings\Drivers\BindingDriver;
use App\Services\Bindings\Kv\RedisEndpoint;
use App\Services\OrgTransfer\Region\BindingDumps;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The tool scripts of binding data (docs/org-export.md#binding-data): credentials only in /ops
 * files, never in the script or a command line; dumps end with the agent's marker; restores wait
 * for the agent's .ready; KV dumps read in SCAN batches under the binding's prefix and restore
 * under the new one.
 */
class BindingDumpsTest extends TestCase
{
    private function dumps(array $env = [], ?RedisEndpoint $endpoint = null): BindingDumps
    {
        $driver = Mockery::mock(BindingDriver::class);
        $driver->shouldReceive('env')->andReturn($env);
        /** @var BindingDrivers&MockInterface $drivers */
        $drivers = Mockery::mock(BindingDrivers::class);
        $drivers->shouldReceive('for')->andReturn($driver);
        /** @var KvService&MockInterface $kv */
        $kv = Mockery::mock(KvService::class);
        $kv->shouldReceive('endpoint')->andReturn($endpoint ?? new RedisEndpoint('runr-kv.runr-data.svc', 6379, 'admin-pw', true, true, 'managed'));

        return new BindingDumps($drivers, $kv);
    }

    private function binding(string $type, array $credentials = []): Binding
    {
        $binding = new Binding(['name' => 'db', 'resource_name' => 'db-abcdefgh', 'type' => $type, 'plan' => 'shared', 'credentials' => $credentials]);
        $binding->setRelation('region', new KnativeRegion(['name' => 'eu-1']));

        return $binding;
    }

    public function test_postgres_dumps_with_pg_dump_and_credentials_in_a_file(): void
    {
        $tool = $this->dumps(['PGHOST' => 'pg-shared-rw', 'PGPORT' => '5432', 'PGDATABASE' => 'b_db', 'PGUSER' => 'b_db', 'PGPASSWORD' => "p'w", 'PGSSLMODE' => 'require', 'DATABASE_URL' => 'postgresql://x'])
            ->dumpTool($this->binding('postgres'));

        $this->assertStringContainsString('pg_dump --format=custom --no-owner --no-privileges', $tool->script);
        $this->assertStringContainsString("'/work/out/data.dump'", $tool->script);
        $this->assertStringContainsString('touch /work/.done', $tool->script);
        $this->assertStringNotContainsString("p'w", $tool->script);
        $this->assertStringContainsString("PGPASSWORD='p'\\''w'", $tool->files['pg.env'], 'single-quoted for sh');
        $this->assertStringNotContainsString('DATABASE_URL', $tool->files['pg.env']);
    }

    public function test_postgres_restore_waits_for_the_file_and_runs_in_one_transaction(): void
    {
        $tool = $this->dumps(['PGDATABASE' => 'b_new', 'PGPASSWORD' => 'pw'])->restoreTool($this->binding('postgres'));

        $this->assertStringContainsString('while [ ! -f /work/in/.ready ]', $tool->script);
        $this->assertStringContainsString('pg_restore --no-owner --no-privileges --single-transaction --exit-on-error', $tool->script);
        $this->assertLessThan(strpos($tool->script, 'pg_restore'), strpos($tool->script, '/work/in/.ready'));
    }

    public function test_kv_dumps_in_scan_batches_under_the_prefix(): void
    {
        $tool = $this->dumps()->dumpTool($this->binding('kv', ['username' => 'db-abcdefgh', 'password' => 'x']));

        $this->assertSame('db-abcdefgh:*', $tool->files['pattern']);
        $this->assertSame('admin-pw', $tool->files['auth']);
        $this->assertStringContainsString("'SCAN', ARGV[1], 'MATCH', ARGV[2], 'COUNT', 200", $tool->files['dump.lua']);
        $this->assertStringContainsString('skip=12', $tool->script, 'the prefix is cut from the keys');
        $this->assertStringContainsString('--user default', $tool->script);
        $this->assertStringNotContainsString('admin-pw', $tool->script);
        // The server's version is the dump's result (the agent serves the .done marker's JSON)
        $this->assertStringContainsString('INFO server', $tool->script);
        $this->assertStringContainsString('{"redis_version":"%s"}', $tool->script);
        $this->assertStringContainsString('mv /work/.result /work/.done', $tool->script);
        $this->assertLessThan(strpos($tool->script, 'touch /work/.done'), strpos($tool->script, 'mv /work/.result'), 'the result is in place before the marker is touched');
    }

    public function test_kv_restores_under_the_new_prefix_or_into_its_database(): void
    {
        $tool = $this->dumps()->restoreTool($this->binding('kv', ['username' => 'db-abcdefgh']));
        $this->assertSame('db-abcdefgh:', $tool->files['prefix']);
        $this->assertStringContainsString("'RESTORE'", $tool->files['restore.lua']);
        $this->assertStringContainsString("'REPLACE'", $tool->files['restore.lua']);

        // a Redis without ACLs: the binding has a database of its own and no prefix
        $tool = $this->dumps(endpoint: new RedisEndpoint('redis.region', 6379, 'pw', false, false, 'region'))
            ->restoreTool($this->binding('kv', ['mode' => 'db', 'db' => 7]));
        $this->assertSame('', $tool->files['prefix']);
        $this->assertStringContainsString('-n 7', $tool->script);
    }

    public function test_objects_are_copied_with_the_bindings_own_key(): void
    {
        $env = ['S3_ENDPOINT' => 'http://garage.runr-data:3900', 'S3_BUCKET' => 'db-abcdefgh', 'S3_ACCESS_KEY_ID' => 'GK1', 'S3_SECRET_ACCESS_KEY' => 'sk', 'S3_REGION' => 'garage', 'S3_FORCE_PATH_STYLE' => 'true'];
        $dumps = $this->dumps($env);

        $dump = $dumps->dumpTool($this->binding('object'));
        $this->assertStringContainsString('rclone copy "t:$S3_BUCKET" /work/stage', $dump->script);
        $this->assertStringContainsString("tar -cf '/work/out/data.tar'", $dump->script);
        $this->assertStringContainsString("RCLONE_CONFIG_T_SECRET_ACCESS_KEY='sk'", $dump->files['rclone.env']);
        $this->assertStringNotContainsString("'sk'", $dump->script);
        $this->assertStringNotContainsString('GK1', $dump->script);

        $upload = $dumps->bucketUploadTool($this->binding('object'), 'package.tar.zst', 'dr/x.tar.zst');
        $this->assertStringContainsString("rclone copyto '/work/in/package.tar.zst' \"t:\$S3_BUCKET/\"'dr/x.tar.zst'", $upload->script);

        // An import read from the bucket: the object goes to /work/out for the agent to serve
        $download = $dumps->bucketDownloadTool($this->binding('object'), "dr/it's.tar.zst", 'package.tar.zst');
        $this->assertStringContainsString("rclone copyto \"t:\$S3_BUCKET/\"'dr/it'\\''s.tar.zst' '/work/out/package.tar.zst'", $download->script, 'the key is quoted');
        $this->assertStringContainsString("[ -s '/work/out/package.tar.zst' ] || fail", $download->script);
        $this->assertStringNotContainsString('GK1', $download->script);
    }
}
