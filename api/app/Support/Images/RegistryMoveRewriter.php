<?php

namespace App\Support\Images;

use App\Models\Runtime;
use App\Services\Runtimes\RuntimeImageBuildService;
use App\Services\Runtimes\RuntimeManifest;
use App\Support\Secrets\SecretCipher;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the image references of one schema (RegistryMoveColumns) to RunR's image namespace
 * (RegistryMove: ghcr.io/runrsh/… → ghcr.io/brf-tech/…).
 *
 * Idempotent and on the connection's current schema (a tenant schema in tenant context): missing
 * tables or columns are skipped, rows without a reference to move are not written. Rows are
 * written through the query builder — no model events, no updated_at change. A row whose value
 * cannot be read (a JSON document that does not parse, an encrypted value no key decrypts) is
 * logged and left as it is.
 */
class RegistryMoveRewriter
{
    /** Primary key of tables that do not use `id`. */
    private const KEY_COLUMNS = [
        'region_supply_chain_settings' => 'knative_region_id',
    ];

    /** @param  bool  $dryRun  count what would move, write nothing */
    public function __construct(private ?string $connection = null, private bool $dryRun = false) {}

    /**
     * @param  array<string, array<string, string>>  $columns  table => [column => kind]
     * @return array<string, int> "table.column" => rows moved
     */
    public function move(array $columns): array
    {
        $moved = [];

        foreach ($columns as $table => $defs) {
            $key = self::KEY_COLUMNS[$table] ?? 'id';
            if (! $this->hasColumn($table, $key)) {
                continue;
            }

            foreach ($defs as $column => $kind) {
                if (! $this->hasColumn($table, $column)) {
                    continue;
                }
                $count = $this->moveColumn($table, $key, $column, $kind);
                if ($count > 0) {
                    $moved["{$table}.{$column}"] = $count;
                }
            }
        }

        return $moved;
    }

    /**
     * Runtime plugins (central schema): the normalized manifest, the runtime.yaml as submitted and
     * the uploaded files move together; the manifest digest follows, and a region build of a
     * version whose source did not change otherwise keeps counting as built (its digest is moved
     * to the source's new digest — the image it built is the same).
     *
     * @return array<string, int> "runtimes" / "runtime_builds" => rows moved
     */
    public function runtimes(): array
    {
        if (! $this->hasColumn('runtimes', 'manifest')) {
            return [];
        }

        $db = $this->db();
        $table = $this->qualifiedTable('runtimes');
        $builds = $this->hasColumn('runtime_builds', 'digest') ? $this->qualifiedTable('runtime_builds') : null;
        $moved = ['runtimes' => 0, 'runtime_builds' => 0];

        $rows = $db->table($table)
            ->select(['id', 'manifest', 'manifest_source', 'files'])
            ->where(function ($q) {
                $this->mentions($q, 'manifest');
                $this->mentions($q, 'manifest_source', 'or');
                $this->mentions($q, 'files', 'or');
            })
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            try {
                $manifest = $this->decodeJson($row->manifest, true);
                $files = $this->decodeJson($row->files, true);
            } catch (\JsonException $e) {
                Log::warning('Image namespace move: runtime left as it is (JSON does not parse)', ['runtime' => $row->id, 'error' => $e->getMessage()]);

                continue;
            }

            $newManifest = RegistryMove::value($manifest);
            $newFiles = RegistryMove::value($files);
            $newSource = $row->manifest_source === null ? null : RegistryMove::text((string) $row->manifest_source);

            if ($newManifest === $manifest && $newFiles === $files && $newSource === $row->manifest_source) {
                continue;
            }

            $changes = ['manifest_source' => $newSource];
            if (is_array($newManifest)) {
                $changes['manifest'] = $this->encodeJson($newManifest, (string) $row->manifest);
                $changes['digest'] = (new RuntimeManifest($newManifest))->digest();
            }
            if ($files !== null) {
                $changes['files'] = $this->encodeJson($newFiles, (string) $row->files);
            }
            if (! $this->dryRun) {
                $db->table($table)->where('id', $row->id)->update($changes);
            }
            $moved['runtimes']++;

            if ($builds && is_array($manifest) && is_array($newManifest)) {
                $moved['runtime_builds'] += $this->moveBuildDigests($builds, (string) $row->id, $manifest, $files, $newManifest, $newFiles);
            }
        }

        return array_filter($moved);
    }

    /**
     * The region builds of a runtime whose source is the same apart from the image namespace:
     * digest of the old source → digest of the new one (RuntimeImageBuildService::digest()).
     */
    private function moveBuildDigests(string $builds, string $id, array $manifest, mixed $files, array $newManifest, mixed $newFiles): int
    {
        $service = app(RuntimeImageBuildService::class);
        $runtime = fn (mixed $f) => tap(new Runtime, fn ($r) => $r->setRawAttributes(['id' => $id, 'files' => is_array($f) ? json_encode($f) : null]));
        $old = $runtime($files);
        $new = $runtime($newFiles);

        $defaults = ['version' => '', 'dockerfile' => null, 'git' => null, 'buildArgs' => []];
        $newSpecs = [];
        foreach ((new RuntimeManifest($newManifest))->versions() as $spec) {
            $spec += $defaults;
            $newSpecs[(string) $spec['version']] = $spec;
        }

        $count = 0;
        foreach ((new RuntimeManifest($manifest))->versions() as $spec) {
            $spec += $defaults;
            $version = (string) $spec['version'];
            if (! isset($newSpecs[$version])) {
                continue;
            }
            $from = $service->digest($old, $spec);
            $to = $service->digest($new, $newSpecs[$version]);
            if ($from === $to) {
                continue;
            }
            $query = $this->db()->table($builds)->where('runtime_id', $id)->where('version', $version)->where('digest', $from);
            $count += $this->dryRun ? $query->count() : $query->update(['digest' => $to]);
        }

        return $count;
    }

    private function moveColumn(string $table, string $key, string $column, string $kind): int
    {
        $db = $this->db();
        $qualified = $this->qualifiedTable($table);
        $count = 0;

        $query = $db->table($qualified)->select([$key, $column])->whereNotNull($column);
        // An encrypted value can only be checked once decrypted; everything else names the namespace
        if ($kind !== RegistryMoveColumns::ENCRYPTED) {
            $this->mentions($query, $column);
        }

        $query->orderBy($key)->chunkById(200, function ($rows) use ($db, $qualified, $key, $column, $kind, $table, &$count) {
            foreach ($rows as $row) {
                $stored = $row->{$column};
                try {
                    $new = $this->movedValue((string) $stored, $kind);
                } catch (\Throwable $e) {
                    Log::warning('Image namespace move: value left as it is', [
                        'table' => $table, 'column' => $column, 'row' => $row->{$key}, 'error' => $e->getMessage(),
                    ]);

                    continue;
                }
                if ($new !== null && $new !== $stored) {
                    if (! $this->dryRun) {
                        $db->table($qualified)->where($key, $row->{$key})->update([$column => $new]);
                    }
                    $count++;
                }
            }
        }, $key);

        return $count;
    }

    /**
     * Rows whose $column names the old namespace somewhere (json / jsonb columns compared as text
     * on PostgreSQL).
     */
    private function mentions($query, string $column, string $boolean = 'and')
    {
        $db = $this->db();
        if ($db->getDriverName() === 'pgsql') {
            return $query->whereRaw('cast('.$db->getQueryGrammar()->wrap($column).' as text) like ?', ['%runrsh%'], $boolean);
        }

        return $query->where($column, 'like', '%runrsh%', $boolean);
    }

    /** The stored value with its references moved; null when nothing has to change. */
    private function movedValue(string $stored, string $kind): ?string
    {
        if ($stored === '') {
            return null;
        }

        switch ($kind) {
            case RegistryMoveColumns::REF:
                return RegistryMove::ref($stored);

            case RegistryMoveColumns::TEXT:
                return RegistryMove::text($stored);

            case RegistryMoveColumns::JSON:
                $value = $this->decodeJson($stored, false);
                $moved = RegistryMove::value($value);

                return $moved == $value ? null : $this->encodeJson($moved, $stored);

            case RegistryMoveColumns::ENCRYPTED:
                $value = SecretCipher::decode($stored, true);
                if (! is_array($value)) {
                    return null;
                }
                $moved = RegistryMove::value($value);

                return $moved === $value ? null : SecretCipher::encode($moved, true);
        }

        return null;
    }

    /** @throws \JsonException */
    private function decodeJson(?string $json, bool $assoc): mixed
    {
        if ($json === null || $json === '') {
            return null;
        }

        return json_decode($json, $assoc, 512, JSON_THROW_ON_ERROR);
    }

    /** JSON in the style of $original: escaped slashes and unicode only where it had them. */
    private function encodeJson(mixed $value, string $original): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;
        if (! str_contains($original, '\\/')) {
            $flags |= JSON_UNESCAPED_SLASHES;
        }
        if (! str_contains($original, '\\u')) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }

        return json_encode($value, $flags);
    }

    private function db(): Connection
    {
        /** @var Connection $connection */
        $connection = DB::connection($this->connection);

        return $connection;
    }

    /**
     * The table and column in the connection's current schema: on PostgreSQL a tenant
     * connection's search_path ends in "public", and Schema::hasTable() would also find a central
     * table the tenant schema does not have.
     */
    private function hasColumn(string $table, string $column): bool
    {
        $db = $this->db();
        if ($db->getDriverName() === 'pgsql') {
            return (bool) $db->scalar(
                'select count(*) from information_schema.columns where table_schema = current_schema() and table_name = ? and column_name = ?',
                [$table, $column],
            );
        }

        $schema = Schema::connection($this->connection);

        return $schema->hasTable($table) && $schema->hasColumn($table, $column);
    }

    /** PostgreSQL: the table in the connection's current schema, explicitly (see hasColumn()). */
    private function qualifiedTable(string $table): string
    {
        $db = $this->db();

        if ($db->getDriverName() === 'pgsql') {
            return (string) $db->scalar('select current_schema()').'.'.$table;
        }

        return $table;
    }
}
