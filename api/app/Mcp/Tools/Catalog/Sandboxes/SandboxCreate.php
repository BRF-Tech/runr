<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class SandboxCreate extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_create';
    }

    public function title(): string
    {
        return 'Create a sandbox';
    }

    public function description(): string
    {
        return 'Creates an isolated sandbox to run untrusted code in: a pod under the region\'s strongest isolation (a Kata Containers micro-VM, else gVisor), as an unprivileged user, without network access unless egress_allow lists hosts, removed after ttl_seconds (default 900) or idle_timeout seconds without activity (default 600). Start from a runtime of the catalog (default python) or any image. Writable: /workspace (working directory), /output, /tmp, /home/sandbox. Waits until it runs (progress while it starts). Then use sandbox_exec, sandbox_run_code, the sandbox_file_* tools and sandbox_process_start; remove it with sandbox_destroy when done.';
    }

    public function inputSchema(): array
    {
        $file = Schema::object([
            'path' => Schema::string('Absolute path, e.g. /workspace/main.py.', ['minLength' => 2, 'maxLength' => 4096, 'pattern' => '^/']),
            'content' => Schema::string('Text content (UTF-8).', ['maxLength' => 10485760]),
            'content_base64' => Schema::string('Binary content, base64 (instead of content).', ['maxLength' => 14000000]),
            'mode' => Schema::string('File mode, e.g. "0755".', ['pattern' => '^0?[0-7]{3}$']),
        ], ['path']);

        return Schema::object([
            'runtime' => Schema::string('A runtime of the catalog (runtimes_list): python (default), nodejs, go, php, …', ['maxLength' => 32]),
            'version' => Schema::string('Runtime version (default: the runtime\'s default).', ['maxLength' => 32]),
            'image' => Schema::string('Any container image instead of a runtime (needs /bin/sh; tar for files), when the platform allows custom images.', ['maxLength' => 512, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._/:@+-]*$']),
            'cpu' => ['type' => ['string', 'number'], 'description' => 'CPU: "500m" (default), 0.5, "1".'],
            'memory' => ['type' => ['string', 'integer'], 'description' => 'Memory in MiB (default 512) or a quantity like "1Gi".'],
            'disk' => ['type' => ['string', 'integer'], 'description' => 'Disk in MiB (default 1024) or a quantity: ephemeral storage and the size of each writable directory.'],
            'ttl_seconds' => Schema::integer('Lifetime in seconds (default the region\'s, 900; at most the region\'s maximum).', 10, 86400),
            'idle_timeout' => Schema::integer('Removed after this many seconds without activity (default 600; 0 = never).', 0, 86400),
            'egress_allow' => Schema::array(Schema::string('Address, CIDR or host name, optionally with a port: pypi.org, files.pythonhosted.org:443, 10.0.0.0/8.', ['minLength' => 1, 'maxLength' => 255]), 'Outbound network the sandbox may reach; absent or empty = no network (the default). Inbound traffic is always denied.', 50),
            'env' => self::envArgument('Environment of every command in the sandbox (stored encrypted; only the names are ever shown again).'),
            'files' => Schema::array($file, 'Files written before the sandbox is returned (up to 200, 10 MiB together).', 200),
            'region' => Schema::string('Region id or name (default: the first region that takes sandboxes; sandbox_quota lists them).', ['maxLength' => 64]),
            'sandbox_class' => Schema::string('auto (default: the strongest allowed), kata (the strongest Kata hypervisor), or a class: kata-fc, kata-clh, kata-dragonball, kata-qemu, gvisor, runc.', ['maxLength' => 64]),
            'read_only_root' => Schema::boolean('Read-only root file system (default true).'),
            'pids_limit' => Schema::integer('Process limit (default 256).', 16, 4096),
            'metadata' => Schema::map(['type' => 'string', 'maxLength' => 1024], 'Your labels (up to 50), filterable in sandboxes_list.'),
            'wait' => Schema::boolean('Wait until the sandbox runs (default true).', true),
            'start_timeout' => Schema::integer('Longest wait for the start in seconds (default 120).', 5, 600),
        ]);
    }

    public function permissions(): array
    {
        return ['sandboxes.create'];
    }

    public function group(): string
    {
        return 'sandboxes';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function streams(): bool
    {
        return true;
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $wait = (bool) ($arguments['wait'] ?? true);
        // The API waits up to start_timeout itself; a start that takes longer is followed here
        $startTimeout = max(5, min(600, (int) ($arguments['start_timeout'] ?? 120), $context->maxWait()));

        $body = array_filter([
            'runtime' => $arguments['runtime'] ?? null,
            'version' => $arguments['version'] ?? null,
            'image' => $arguments['image'] ?? null,
            'cpu' => $arguments['cpu'] ?? null,
            'memory' => $arguments['memory'] ?? null,
            'disk' => $arguments['disk'] ?? null,
            'ttl_seconds' => $arguments['ttl_seconds'] ?? null,
            'idle_timeout' => $arguments['idle_timeout'] ?? null,
            'env' => ! empty($arguments['env']) ? (object) $arguments['env'] : null,
            'region' => $arguments['region'] ?? null,
            'sandbox_class' => $arguments['sandbox_class'] ?? null,
            'read_only_root' => $arguments['read_only_root'] ?? null,
            'pids_limit' => $arguments['pids_limit'] ?? null,
            'metadata' => ! empty($arguments['metadata']) ? (object) $arguments['metadata'] : null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('egress_allow', $arguments)) {
            $body['network'] = $arguments['egress_allow'] === [] ? 'none' : ['egress_allow' => array_values($arguments['egress_allow'])];
        }
        if (! empty($arguments['files'])) {
            $body['files'] = array_map([self::class, 'file'], $arguments['files']);
        }
        $body['wait'] = $wait;
        if ($wait) {
            $body['start_timeout'] = $startTimeout;
        }

        $context->progress(0, null, 'Creating the sandbox…');
        $result = $context->api()->post('/sandboxes', $body);

        if ($result->status === 502 && is_array($result->get('data'))) {
            $failed = self::sandboxSummary((array) $result->get('data'));
            $context->setTarget('sandbox', $failed['id'] ?? null);

            return ToolResult::error('The sandbox could not start: '.($failed['error'] ?? $result->message()), ['reason' => 'start_failed', 'sandbox' => $failed]);
        }
        if (! $result->ok()) {
            throw $this->sandboxError($result, 'The sandbox was not created');
        }

        $sandbox = (array) $result->data();
        $context->setTarget('sandbox', isset($sandbox['id']) ? (string) $sandbox['id'] : null);
        if ($wait) {
            $sandbox = $this->waitForSandbox($context, $sandbox, time() + $context->maxWait());
        }

        $summary = self::sandboxSummary($sandbox);
        $id = $summary['id'] ?? '?';

        return match ($summary['status'] ?? null) {
            'running' => ToolResult::ok(['sandbox' => $summary], sprintf(
                'Sandbox %s runs (%s, %s)%s; it expires at %s. Next: sandbox_exec or sandbox_run_code; sandbox_destroy when done.',
                $id,
                $summary['sandbox_class'] ?? '?',
                $summary['runtime'] ?? $summary['image'] ?? '?',
                isset($summary['start_ms']) ? ' after '.$summary['start_ms'].' ms' : '',
                $summary['expires_at'] ?? '?',
            )),
            'failed', 'terminated' => ToolResult::error('The sandbox could not start: '.($summary['error'] ?? 'no details'), ['sandbox' => $summary]),
            default => ToolResult::ok(['sandbox' => $summary], "Sandbox {$id} is still starting; sandbox_get shows when it runs."),
        };
    }

    /** One file of the request: text content becomes content_base64. */
    private static function file(array $file): array
    {
        if (isset($file['content'], $file['content_base64'])) {
            throw new ToolError("File {$file['path']}: give content or content_base64, not both.");
        }

        return array_filter([
            'path' => (string) $file['path'],
            'content_base64' => isset($file['content']) ? base64_encode((string) $file['content']) : (string) ($file['content_base64'] ?? ''),
            'mode' => $file['mode'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
