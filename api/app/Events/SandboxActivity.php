<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something was done with a sandbox (docs/sandbox-api.md → Audit): the hook an audit log listens
 * to. $action is one of the SandboxLog::* types — sandbox.create, sandbox.delete, sandbox.exec,
 * sandbox.run, sandbox.terminal, sandbox.file_upload, sandbox.file_download, sandbox.file_delete,
 * sandbox.process_start, sandbox.process_kill, sandbox.timeout, sandbox.network, sandbox.pause,
 * sandbox.resume, sandbox.expire. $details never carries command output or file contents.
 */
class SandboxActivity
{
    use Dispatchable;

    public function __construct(
        public readonly string $action,
        public readonly string $sandboxId,
        public readonly ?string $tenantId,
        public readonly ?string $userId,
        public readonly array $details = [],
    ) {}
}
