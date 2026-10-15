<?php

namespace App\Mcp\Tools;

use App\Mcp\Server\InternalApi;
use App\Mcp\Server\MessageSink;
use App\Mcp\Server\NullSink;
use App\Support\Rbac\Rbac;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * What a tool sees while it runs: the caller, the internal API (InternalApi — every RunR
 * action goes through the API routes), progress and log notifications for streamed calls, the
 * cancellation flag, and the audit target of the call.
 */
final class ToolContext
{
    public const LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /** @var (\Closure(int): void)|null test hook replacing usleep() */
    private static ?\Closure $sleeper = null;

    private ?array $target = null;

    private ?InternalApi $api = null;

    public function __construct(
        public readonly Request $request,
        public readonly Authenticatable $user,
        public readonly string $tool,
        public readonly string $protocolVersion = '2025-11-25',
        private readonly MessageSink $sink = new NullSink,
        private readonly string|int|null $progressToken = null,
        private readonly ?string $sessionKey = null,
        private readonly string|int|null $requestId = null,
        private readonly string $logLevel = 'info',
    ) {}

    public static function sleepUsing(?\Closure $sleeper): void
    {
        self::$sleeper = $sleeper;
    }

    public function api(): InternalApi
    {
        return $this->api ??= new InternalApi($this->request, $this->tool);
    }

    public function can(string $permission, mixed $scope = null): bool
    {
        return app(Rbac::class)->allows($this->user, $permission, $scope);
    }

    public function streaming(): bool
    {
        return $this->sink->streaming();
    }

    /** notifications/progress — only when the client asked for progress (params._meta.progressToken). */
    public function progress(int|float $progress, int|float|null $total = null, ?string $message = null): void
    {
        if ($this->progressToken === null) {
            return;
        }

        $params = ['progressToken' => $this->progressToken, 'progress' => $progress];
        if ($total !== null) {
            $params['total'] = $total;
        }
        if ($message !== null && $this->protocolVersion >= '2025-03-26') {
            $params['message'] = $message;
        }

        $this->sink->notify('notifications/progress', $params);
    }

    /** notifications/message at $level, when the session's logging level lets it through. */
    public function log(string $level, string $message, ?array $data = null): void
    {
        $at = array_search($level, self::LOG_LEVELS, true);
        $min = array_search($this->logLevel, self::LOG_LEVELS, true);
        if ($at === false || ($min !== false && $at < $min)) {
            return;
        }

        $this->sink->notify('notifications/message', [
            'level' => $level,
            'logger' => 'runr.'.$this->tool,
            'data' => $data === null ? $message : ['message' => $message] + $data,
        ]);
    }

    /** The client sent notifications/cancelled for this request (McpServer flags it in the cache). */
    public function cancelled(): bool
    {
        if ($this->sessionKey === null || $this->requestId === null) {
            return false;
        }

        try {
            return Cache::has(self::cancelKey($this->sessionKey, $this->requestId));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function cancelKey(string $sessionKey, string|int $requestId): string
    {
        return 'mcp:cancel:'.$sessionKey.':'.md5((string) $requestId);
    }

    /**
     * Waits between two polls of a long-running tool; keeps the SSE stream alive. Returns false
     * when the request was cancelled or the client went away.
     */
    public function pause(?int $milliseconds = null): bool
    {
        $ms = $milliseconds ?? (int) config('mcp.tools.poll_interval_ms', 2000);
        if (self::$sleeper) {
            (self::$sleeper)($ms);
        } elseif ($ms > 0) {
            usleep($ms * 1000);
        }

        $this->sink->keepAlive();

        return ! $this->cancelled() && ! (connection_status() & CONNECTION_ABORTED);
    }

    /** Longest a long-running tool waits, capped by config mcp.tools.max_wait_seconds. */
    public function maxWait(?int $requested = null): int
    {
        $cap = max(1, (int) config('mcp.tools.max_wait_seconds', 600));

        return $requested === null ? $cap : max(0, min($requested, $cap));
    }

    /**
     * Resolves a project reference (id, slug or exact name) to its id through the API — only
     * projects the caller may see are found.
     *
     * @throws ToolError
     */
    public function projectId(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new ToolError('A project is required.');
        }

        // Project ids are ULIDs, stored lower-case (Eloquent HasUlids)
        if (preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $reference)) {
            $this->target ??= ['type' => 'project', 'id' => strtolower($reference)];

            return strtolower($reference);
        }

        $result = $this->api()->get('/projects', ['search' => $reference]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Listing projects');
        }

        $needle = mb_strtolower($reference);
        foreach ((array) $result->data() as $project) {
            if (! is_array($project)) {
                continue;
            }
            if (mb_strtolower((string) ($project['slug'] ?? '')) === $needle || mb_strtolower((string) ($project['name'] ?? '')) === $needle) {
                $this->target ??= ['type' => 'project', 'id' => (string) $project['id'], 'name' => (string) ($project['name'] ?? '')];

                return (string) $project['id'];
            }
        }

        throw new ToolError("No project \"{$reference}\" that you can see. Call projects_list for the projects you have access to.");
    }

    /** The target the audit event of this call names (the first project resolved, unless set). */
    public function setTarget(string $type, ?string $id = null, ?string $name = null): void
    {
        $this->target = array_filter(['type' => $type, 'id' => $id, 'name' => $name], fn ($v) => $v !== null && $v !== '');
    }

    public function target(): ?array
    {
        return $this->target;
    }
}
