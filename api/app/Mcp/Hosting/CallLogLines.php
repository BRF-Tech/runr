<?php

namespace App\Mcp\Hosting;

use App\Mcp\Models\HostedServerCall;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * The gateway's call log in a project's deployment logs (GET …/deployments/{deployment}/logs):
 * calls appear as the pod "mcp-gateway" with "<unix ms>\t<line>" entries, merged by time with
 * the pods' own lines — the format KnativeLokiService produces.
 */
final class CallLogLines
{
    public const POD = 'mcp-gateway';

    private const LIMIT = 500;

    private const LOOKBACK_S = 86400;

    /**
     * @param  array  $logs  the log service's answer: logs (text), last_ts_ms, pods, …
     */
    public static function mergeInto(Project $project, array $logs, ?int $sinceMs = null): array
    {
        try {
            $calls = HostedServerCall::query()
                ->where('project_id', $project->id)
                ->where('occurred_at', '>', $sinceMs ? Carbon::createFromTimestampMs($sinceMs) : now()->subSeconds(self::LOOKBACK_S))
                ->orderByDesc('occurred_at')
                ->limit(self::LIMIT)
                ->get()
                ->reverse()
                ->values();
        } catch (\Throwable $e) {
            report($e);

            return $logs;
        }

        if ($calls->isEmpty()) {
            return $logs;
        }

        $entries = [];
        foreach ($calls as $call) {
            $entries[] = [(int) $call->occurred_at->getTimestampMs(), self::POD, self::line($call)];
        }

        $text = is_string($logs['logs'] ?? null) ? $logs['logs'] : '';
        $logs['logs'] = self::merge($text, $entries);
        $last = max(array_column($entries, 0));
        $logs['last_ts_ms'] = max((int) ($logs['last_ts_ms'] ?? 0), $last);

        return $logs;
    }

    public static function line(HostedServerCall $call): string
    {
        $who = $call->actor_name ?: ($call->actor_type === 'anonymous' ? 'anonymous' : ($call->actor_id ?? 'unknown'));

        return trim(sprintf(
            '[mcp] %s%s → %d %s %dms %s (%s)%s',
            $call->method,
            $call->name ? ' '.$call->name : '',
            $call->status_code,
            $call->outcome,
            $call->duration_ms,
            $who,
            $call->auth,
            $call->error ? ' — '.$call->error : '',
        ));
    }

    /**
     * Interleaves the call entries with timestamped pod lines; text without timestamps (kubectl
     * logs) keeps its order and gets the calls as a block of their own at the end.
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $entries
     */
    private static function merge(string $text, array $entries): string
    {
        $lines = [];
        $pod = null;
        $plain = false;
        foreach (preg_split('/\r?\n/', $text) as $raw) {
            if ($raw === '') {
                continue;
            }
            if (preg_match('/^=== (.+) ===$/', $raw, $m)) {
                $pod = $m[1];

                continue;
            }
            if (preg_match('/^(\d{13})\t(.*)$/s', $raw, $m)) {
                $lines[] = [(int) $m[1], $pod ?? 'unknown', $m[2]];
            } else {
                $plain = true;
                break;
            }
        }

        if ($plain) {
            $out = rtrim($text, "\n")."\n=== ".self::POD." ===\n";
            foreach ($entries as [$ms, , $line]) {
                $out .= $ms."\t".$line."\n";
            }

            return $out;
        }

        $lines = array_merge($lines, $entries);
        usort($lines, fn ($a, $b) => $a[0] <=> $b[0]);

        $out = '';
        $current = null;
        foreach ($lines as [$ms, $linePod, $line]) {
            if ($linePod !== $current) {
                $out .= "=== {$linePod} ===\n";
                $current = $linePod;
            }
            $out .= $ms."\t".$line."\n";
        }

        return $out;
    }
}
