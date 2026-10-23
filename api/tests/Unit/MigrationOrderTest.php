<?php

namespace Tests\Unit;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * Migrations run in file name order (Laravel's migrator and stancl's tenants:migrate sort by
 * basename; several files may share a number or timestamp, then the rest of the name decides).
 * No migration may reference a table that a later-sorting migration creates: foreign keys
 * (->on(), ->constrained()), Schema::table() and DB::table() of tables created further down the
 * list would fail on a fresh install even when an upgraded install, which already has them,
 * passes.
 */
class MigrationOrderTest extends TestCase
{
    /** Shared schema helpers the migrations call, read along with the migration. */
    private const HELPERS = [
        'WorkflowSchema::' => 'app/Workflows/Database/WorkflowSchema.php',
        'ObservabilitySchema::' => 'app/Services/Observability/ObservabilitySchema.php',
        'EnvironmentSchema::' => 'app/Services/Environments/EnvironmentSchema.php',
    ];

    private function api(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }

    /** The migration's source plus the helpers and required central migrations it runs. */
    private function source(string $file, array &$seen = []): string
    {
        if (isset($seen[$file]) || ! is_file($file)) {
            return '';
        }
        $seen[$file] = true;
        $text = (string) file_get_contents($file);

        preg_match_all("/require\s+base_path\('([^']+)'\)/", $text, $m);
        foreach ($m[1] as $required) {
            $text .= $this->source($this->api($required), $seen);
        }
        foreach (self::HELPERS as $marker => $helper) {
            if (str_contains($text, $marker)) {
                $text .= $this->source($this->api($helper), $seen);
            }
        }

        return $text;
    }

    /** @return array{0: list<string>, 1: list<string>} [created tables, referenced tables] */
    private function tables(string $source): array
    {
        preg_match_all("/Schema::(?:connection\([^)]*\)->)?create\('([a-z0-9_]+)'/", $source, $created);

        $refs = [];
        foreach ([
            "/Schema::(?:connection\([^)]*\)->)?table\('([a-z0-9_]+)'/",
            "/->on\('([a-z0-9_]+)'\)/",
            "/->constrained\('([a-z0-9_]+)'/",
            "/DB::table\('([a-z0-9_]+)'\)/",
        ] as $pattern) {
            preg_match_all($pattern, $source, $m);
            array_push($refs, ...$m[1]);
        }
        // foreignUlid('project_id')->…->constrained(): the table Laravel derives from the column
        preg_match_all("/foreign(?:Ulid|Id|Uuid)?\('([a-z0-9_]+)_id'\)(?:->[a-zA-Z]+\([^)]*\))*?->constrained\(\)/", $source, $m);
        foreach ($m[1] as $base) {
            $refs[] = Str::plural($base);
        }

        $created = array_values(array_unique($created[1]));

        return [$created, array_values(array_diff(array_unique($refs), $created))];
    }

    /** @param list<string> $files */
    private function problems(array $files): array
    {
        usort($files, fn (string $a, string $b) => strcmp(basename($a), basename($b)));

        $createdAt = [];
        $refs = [];
        foreach ($files as $i => $file) {
            [$created, $referenced] = $this->tables($this->source($file));
            foreach ($created as $table) {
                $createdAt[$table] ??= $i;
            }
            $refs[$i] = $referenced;
        }

        $problems = [];
        foreach ($refs as $i => $referenced) {
            foreach ($referenced as $table) {
                if (isset($createdAt[$table]) && $createdAt[$table] > $i) {
                    $problems[] = basename($files[$i])." uses {$table}, created later by ".basename($files[$createdAt[$table]]);
                }
            }
        }

        return $problems;
    }

    public function test_tenant_migrations_only_use_tables_of_earlier_migrations(): void
    {
        $files = glob($this->api('Modules/Tenancy/Database/TenantMigrations/*.php'));

        $this->assertGreaterThan(40, count($files));
        $this->assertSame([], $this->problems($files));
    }

    public function test_central_migrations_only_use_tables_of_earlier_migrations(): void
    {
        $core = glob($this->api('database/migrations/*.php'));
        $modules = glob($this->api('Modules/*/Database/Migrations/*.php'));

        $this->assertGreaterThan(80, count($core));
        // docker/entrypoint.sh runs the core migrations first, then each module's; `php artisan
        // migrate` with the modules on runs all of them in one sorted list — both orders hold
        $this->assertSame([], $this->problems($core), 'core migrations');
        $this->assertSame([], $this->problems([...$core, ...$modules]), 'core and module migrations in one run');
    }

    public function test_the_check_sees_a_reference_to_a_later_table(): void
    {
        $dir = sys_get_temp_dir().'/runr-migration-order-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents("{$dir}/0001_create_a.php", "<?php Schema::create('a', fn (\$t) => \$t->foreignUlid('order_id')->constrained());");
            file_put_contents("{$dir}/0002_create_orders.php", "<?php Schema::create('orders', fn (\$t) => \$t->ulid('id'));");

            $this->assertSame(['0001_create_a.php uses orders, created later by 0002_create_orders.php'], $this->problems(glob("{$dir}/*.php")));
        } finally {
            array_map('unlink', glob("{$dir}/*.php"));
            rmdir($dir);
        }
    }
}
