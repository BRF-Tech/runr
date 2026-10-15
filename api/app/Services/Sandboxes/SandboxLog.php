<?php

namespace App\Services\Sandboxes;

use App\Events\SandboxActivity;
use App\Models\Sandbox;
use App\Models\SandboxEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Log;

/**
 * The activity log of a sandbox (sandbox_events, GET /sandboxes/{id}/logs) and the audit hook
 * (App\Events\SandboxActivity) in one call.
 */
final class SandboxLog
{
    public const CREATE = 'sandbox.create';

    public const START = 'sandbox.start';

    public const DELETE = 'sandbox.delete';

    public const EXPIRE = 'sandbox.expire';

    public const FAIL = 'sandbox.fail';

    public const EXEC = 'sandbox.exec';

    public const RUN = 'sandbox.run';

    public const TERMINAL = 'sandbox.terminal';

    public const FILE_UPLOAD = 'sandbox.file_upload';

    public const FILE_DOWNLOAD = 'sandbox.file_download';

    public const FILE_DELETE = 'sandbox.file_delete';

    public const PROCESS_START = 'sandbox.process_start';

    public const PROCESS_KILL = 'sandbox.process_kill';

    public const TIMEOUT = 'sandbox.timeout';

    public const NETWORK = 'sandbox.network';

    public const PAUSE = 'sandbox.pause';

    public const RESUME = 'sandbox.resume';

    /** Actions that are audit-relevant (the rest is lifecycle noise of the platform itself). */
    public const AUDITABLE = [
        self::CREATE, self::DELETE, self::EXEC, self::RUN, self::TERMINAL, self::FILE_UPLOAD,
        self::FILE_DOWNLOAD, self::FILE_DELETE, self::PROCESS_START, self::PROCESS_KILL,
        self::TIMEOUT, self::NETWORK, self::PAUSE, self::RESUME, self::EXPIRE,
    ];

    public static function record(Sandbox $sandbox, string $type, ?string $message = null, array $data = [], ?string $userId = null): void
    {
        $userId ??= auth()->id() !== null ? (string) auth()->id() : null;

        try {
            SandboxEvent::create([
                'sandbox_id' => $sandbox->id,
                'type' => $type,
                'message' => $message !== null ? mb_substr($message, 0, 1000) : null,
                'data' => $data ?: null,
                'user_id' => $userId,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('sandbox event not recorded', ['sandbox' => $sandbox->id, 'type' => $type, 'error' => $e->getMessage()]);
        }

        if (in_array($type, self::AUDITABLE, true)) {
            SandboxActivity::dispatch($type, $sandbox->id, $sandbox->tenant_id, $userId, $data + array_filter(['message' => $message]));
        }

        // API requests are audited by their route (AuditCatalog); the reaper's own actions here
        if ($type === self::EXPIRE) {
            app(AuditLogger::class)->record('sandbox.expire', [
                'actor' => 'system',
                'tenant_id' => $sandbox->tenant_id,
                'target' => ['type' => 'sandbox', 'id' => $sandbox->id, 'name' => $sandbox->pod_name],
                'metadata' => $data,
            ]);
        }
    }

    /** A command line for the log: argv joined, at most 1000 characters. */
    public static function commandLine(string|array $cmd): string
    {
        $line = is_array($cmd) ? implode(' ', array_map(fn ($a) => preg_match('/[\s"\'\\\\]/', (string) $a) ? json_encode((string) $a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $a, $cmd)) : $cmd;

        return mb_substr($line, 0, 1000);
    }
}
