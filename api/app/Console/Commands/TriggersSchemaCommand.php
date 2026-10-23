<?php

namespace App\Console\Commands;

use App\Triggers\TriggerSchema;
use Illuminate\Console\Command;

/**
 * triggers:schema — writes resources/triggers/triggers.schema.json, the schema of a runr.yaml
 * `triggers:` entry derived from the trigger types' validation rules (App\Triggers\TriggerSchema).
 * The CLI embeds a copy (cli/internal/projectfile/triggers.schema.json). --check only compares
 * and fails when the file is out of date (a trigger type's rules changed).
 */
class TriggersSchemaCommand extends Command
{
    protected $signature = 'triggers:schema {--check : fail when the file differs instead of writing it}';

    protected $description = 'Write the runr.yaml trigger schema derived from the trigger types (resources/triggers/triggers.schema.json)';

    public function handle(TriggerSchema $schema): int
    {
        $path = TriggerSchema::path();
        $json = $schema->render();
        $current = is_file($path) ? (string) file_get_contents($path) : null;

        if ($this->option('check')) {
            if ($current !== $json) {
                $this->error("{$path} is out of date: run php artisan triggers:schema and copy it to cli/internal/projectfile/triggers.schema.json");

                return self::FAILURE;
            }
            $this->info("{$path} is up to date");

            return self::SUCCESS;
        }

        if ($current === $json) {
            $this->info("{$path} is up to date");

            return self::SUCCESS;
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $json);
        $this->info("Wrote {$path}; copy it to cli/internal/projectfile/triggers.schema.json");

        return self::SUCCESS;
    }
}
