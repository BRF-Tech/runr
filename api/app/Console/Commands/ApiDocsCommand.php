<?php

namespace App\Console\Commands;

use App\Support\ApiDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Builds the OpenAPI document GET /api/docs.json serves (App\Support\ApiDocument): a stage of
 * api/Dockerfile.prod runs it once per image, with every module enabled. Generating the document
 * takes longer than a request may run, which is why requests no longer do it.
 *
 * Not `scramble:export`: that one writes Scramble's own document (the schema names the CLI's
 * contract test reads, cli/scripts/export-openapi.sh); this one is the document as the API serves
 * it, with ApiDocument::finish().
 *
 * --check fails when an operation breaks the reference's rules (ApiDocument::problems(): a one-line
 * summary, an operationId of its own, one group): the image build and CI (api/docker/api-docs.sh)
 * build the document with it, every module enabled.
 */
class ApiDocsCommand extends Command
{
    protected $signature = 'runr:api-docs
        {--path= : Where to write the document (default: config scramble.document_path, the file /api/docs.json serves)}
        {--check : Fail (after writing it) when an operation of the document breaks a rule of the reference}';

    protected $description = 'Build the OpenAPI document /api/docs.json serves';

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: ApiDocument::path());

        $started = microtime(true);
        $document = ApiDocument::generate();
        $json = json_encode($document, JSON_THROW_ON_ERROR);
        $seconds = microtime(true) - $started;

        // Written next to its place and moved there: a request never reads half a document
        File::ensureDirectoryExists(dirname($path));
        $partial = $path.'.'.getmypid().'.tmp';
        File::put($partial, $json);
        File::move($partial, $path);

        $this->info(sprintf(
            'OpenAPI document written to %s: %d paths, %d operations, %d KB, generated in %.1f s.',
            $path,
            count($document['paths'] ?? []),
            array_sum(array_map(fn ($item) => count(array_intersect_key((array) $item, array_flip(['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace']))), $document['paths'] ?? [])),
            intdiv(strlen($json), 1024),
            $seconds,
        ));

        if (! $this->option('check')) {
            return self::SUCCESS;
        }

        $problems = ApiDocument::problems($document);
        foreach ($problems as $problem) {
            $this->line($problem);
        }
        if ($problems !== []) {
            $this->error(sprintf('%d problems in the API reference (ApiDocument::problems)', count($problems)));

            return self::FAILURE;
        }
        $this->info('Every operation has a one-line summary, an operationId of its own and one group.');

        return self::SUCCESS;
    }
}
