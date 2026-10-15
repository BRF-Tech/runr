<?php

namespace App\Mcp\Console;

use App\Mcp\Hosting\McpTemplates;
use App\Services\Runtimes\InvalidManifestException;
use Illuminate\Console\Command;

/**
 * Regenerates resources/mcp/templates.json — the MCP server starters the API image ships — from
 * examples/mcp/<template>/runtime.yaml and the files it references. --check only reports whether
 * the bundle is current (CI, McpTemplatesTest).
 */
class McpTemplatesBundleCommand extends Command
{
    protected $signature = 'mcp:templates-bundle {--check : Fail when the bundle differs from the sources instead of writing it}';

    protected $description = 'Bundle the MCP server starters (examples/mcp/*/runtime.yaml) into resources/mcp/templates.json';

    public function handle(McpTemplates $templates): int
    {
        $dir = McpTemplates::sourceDir();
        if (! $dir) {
            $this->error('examples/mcp with */runtime.yaml not found next to the API.');

            return 1;
        }

        try {
            $json = $templates->bundleJson($dir);
        } catch (InvalidManifestException $e) {
            foreach ($e->problems as $problem) {
                $this->error("{$problem['path']}: {$problem['message']}");
            }

            return 1;
        }

        $path = McpTemplates::bundlePath();
        $current = is_file($path) ? file_get_contents($path) : null;

        if ($this->option('check')) {
            if ($current !== $json) {
                $this->error('resources/mcp/templates.json is out of date: run php artisan mcp:templates-bundle');

                return 1;
            }
            $this->info('MCP template bundle is current.');

            return 0;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $json);
        McpTemplates::flush();
        $this->info('Wrote '.$path.' ('.count(json_decode($json, true)['templates']).' templates).');

        return 0;
    }
}
