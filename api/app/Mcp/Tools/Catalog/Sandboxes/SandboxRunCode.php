<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxRunCode extends Tool
{
    use UsesSandboxes;

    /** Changed files listed at most. */
    private const MAX_FILES = 100;

    public function name(): string
    {
        return 'sandbox_run_code';
    }

    public function title(): string
    {
        return 'Run code in a sandbox (code interpreter)';
    }

    public function description(): string
    {
        return 'Code interpreter: runs a snippet with the language\'s interpreter in a running sandbox — python (default for Python sandboxes), javascript, typescript, bash, sh, php, ruby, go or a runtime id — and returns exit code, stdout, stderr, the files the run created or changed under /workspace and /output, and the images it wrote to /output (png, jpg, gif, svg, webp; RUNR_OUTPUT_DIR=/output, MPLBACKEND=Agg, so plt.savefig(os.environ["RUNR_OUTPUT_DIR"] + "/chart.png") is all a chart needs). Image bytes are not inlined: read one with sandbox_file_read. State lives in files, not between calls.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'code' => Schema::string('The code.', ['minLength' => 1, 'maxLength' => 10485760]),
            'language' => Schema::string('python, javascript, typescript, bash, sh, php, ruby, go, or a runtime id (default: the sandbox runtime\'s language).', ['maxLength' => 32]),
            'timeout' => Schema::integer('Seconds before the run is killed (default 60).', 1, 3600),
            'env' => self::envArgument('Extra environment variables of this run.'),
            'cwd' => Schema::string('Working directory (default /workspace).', ['minLength' => 1, 'maxLength' => 4096, 'pattern' => '^/']),
        ], ['sandbox', 'code']);
    }

    public function permissions(): array
    {
        return ['sandboxes.exec'];
    }

    public function group(): string
    {
        return 'sandboxes';
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true];
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = (string) $arguments['sandbox'];
        $context->setTarget('sandbox', $id);

        $body = array_filter([
            'code' => $arguments['code'],
            'language' => $arguments['language'] ?? null,
            'timeout' => $arguments['timeout'] ?? null,
            'env' => ! empty($arguments['env']) ? (object) $arguments['env'] : null,
            'cwd' => $arguments['cwd'] ?? null,
        ], fn ($v) => $v !== null);

        $run = (array) $this->sandboxCall($context, 'POST', self::sandboxPath($id, 'run'), $body, [], 'The code did not run')->data();
        $result = self::capOutput(array_diff_key($run, ['outputs' => true, 'files_changed' => true]));

        $files = array_values(array_filter((array) ($run['files_changed'] ?? []), 'is_array'));
        if ($files !== []) {
            $result['files_changed'] = array_slice($files, 0, self::MAX_FILES);
            if (count($files) > self::MAX_FILES) {
                $result['files_changed_total'] = count($files);
            }
        }
        $outputs = array_map(fn (array $o) => array_filter([
            'path' => $o['path'] ?? null,
            'mime' => $o['mime'] ?? null,
            'size' => $o['size'] ?? null,
        ], fn ($v) => $v !== null), array_values(array_filter((array) ($run['outputs'] ?? []), 'is_array')));
        if ($outputs !== []) {
            $result['outputs'] = $outputs;
        }

        $exit = (int) ($result['exit_code'] ?? -1);
        $summary = match (true) {
            ! empty($result['timed_out']) => "Killed after the timeout (exit {$exit}).",
            $exit === 0 => 'Ran ('.($result['language'] ?? '?').') in '.($result['duration_ms'] ?? '?').' ms.',
            default => "Exited with {$exit}.",
        };
        if ($outputs !== []) {
            $summary .= ' '.count($outputs).' image output(s) in /output (sandbox_file_read).';
        }

        return ToolResult::ok($result, $summary);
    }
}
