<?php

namespace App\Support\Secrets;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the secret columns of one schema (SecretColumns) to "encrypted with the current key":
 *
 *  - widenColumns(): JSON secret columns become text (PostgreSQL / MySQL json and jsonb columns
 *    only accept JSON; an encrypter payload is base64 text)
 *  - encrypt(): plaintext rows are encrypted; with $rotate, rows encrypted with a previous key
 *    (APP_PREVIOUS_KEYS) are re-encrypted with APP_KEY
 *
 * Both are idempotent and work on the connection's current schema (a tenant schema in tenant
 * context): missing tables or columns are skipped, rows that are already right are left alone.
 * Rows are written through the query builder — no model events, no updated_at change.
 */
class SecretColumnEncryptor
{
    public function __construct(private ?string $connection = null) {}

    /** @param array<string, array<string, string>> $columns table => [column => kind] */
    public function widenColumns(array $columns): void
    {
        $db = $this->db();
        $driver = $db->getDriverName();

        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            return; // SQLite: dynamically typed, JSON columns already hold text
        }

        foreach ($columns as $table => $defs) {
            foreach ($defs as $column => $kind) {
                if ($kind !== SecretColumns::JSON || ! $this->hasColumn($table, $column)) {
                    continue;
                }

                $type = strtolower((string) Schema::connection($this->connection)->getColumnType($table, $column));
                if (! in_array($type, ['json', 'jsonb'], true)) {
                    continue;
                }

                $qualified = $this->qualifiedTable($table);
                $col = $db->getQueryGrammar()->wrap($column);

                $driver === 'pgsql'
                    ? $db->statement("ALTER TABLE {$qualified} ALTER COLUMN {$col} TYPE text USING {$col}::text")
                    : $db->statement("ALTER TABLE {$qualified} MODIFY {$col} LONGTEXT NULL");
            }
        }
    }

    /**
     * @param  array<string, array<string, string>>  $columns  table => [column => kind]
     * @return array{encrypted: int, rotated: int}
     */
    public function encrypt(array $columns, bool $rotate = false): array
    {
        $counts = ['encrypted' => 0, 'rotated' => 0];
        $db = $this->db();

        foreach ($columns as $table => $defs) {
            $present = array_filter(
                array_keys($defs),
                fn (string $column) => $this->hasColumn($table, $column),
            );
            $key = SecretColumns::keyColumn($table);
            if (! $present || ! $this->hasColumn($table, $key)) {
                continue;
            }

            $qualified = $this->qualifiedTable($table, wrapped: false);

            $db->table($qualified)
                ->select(array_merge([$key], $present))
                ->orderBy($key)
                ->chunkById(200, function ($rows) use ($db, $qualified, $key, $defs, $present, $rotate, &$counts) {
                    foreach ($rows as $row) {
                        $changes = [];

                        foreach ($present as $column) {
                            $stored = $row->{$column};
                            if ($stored === null || $stored === '') {
                                continue;
                            }

                            $isPlain = ! SecretCipher::isEncrypted($stored);
                            if ($isPlain && $defs[$column] === SecretColumns::KEYED) {
                                continue; // written encrypted by the application, never plaintext
                            }

                            $new = SecretCipher::upgrade((string) $stored, $rotate);
                            if ($new !== null) {
                                $changes[$column] = $new;
                                $counts[$isPlain ? 'encrypted' : 'rotated']++;
                            }
                        }

                        if ($changes) {
                            $db->table($qualified)->where($key, $row->{$key})->update($changes);
                        }
                    }
                }, $key);
        }

        return $counts;
    }

    private function db(): Connection
    {
        /** @var Connection $connection */
        $connection = DB::connection($this->connection);

        return $connection;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $schema = Schema::connection($this->connection);

        return $schema->hasTable($table) && $schema->hasColumn($table, $column);
    }

    /**
     * PostgreSQL: the table in the connection's current schema, explicitly — a tenant
     * connection's search_path ends in "public", and an unqualified name of a table the tenant
     * schema does not have would resolve to the central one.
     */
    private function qualifiedTable(string $table, bool $wrapped = true): string
    {
        $db = $this->db();

        if ($db->getDriverName() === 'pgsql') {
            $schema = (string) $db->scalar('select current_schema()');
            $name = "{$schema}.{$table}";
        } else {
            $name = $table;
        }

        return $wrapped ? $db->getQueryGrammar()->wrapTable($name) : $name;
    }
}
