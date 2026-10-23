<?php

namespace App\Services\Observability\Replay;

use App\Models\Observability\ProjectObservabilitySetting;
use App\Models\Observability\ReplayCapture;
use App\Models\Project;
use App\Models\Setting;
use App\Services\Observability\ReplayTokens;
use App\Services\Observability\TenantScope;
use App\Support\Secrets\SecretRedactor;
use App\Triggers\ProjectTriggers;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Request replay (docs/observability.md#request-replay): stores the failed requests a project's
 * function pods capture (opt-in, redacted in the pod and again here, body limit, retention,
 * capture limit) and re-sends one to the project's current revision, answering with both
 * responses and their difference.
 */
class ReplayService
{
    public const INGEST_STORED = 'stored';

    public const INGEST_UNAUTHORIZED = 'unauthorized';

    public const INGEST_INVALID = 'invalid';

    /** Never forwarded on replay: connection-level, proxy-added and trace headers. */
    private const NOT_REPLAYED = [
        'host', 'content-length', 'connection', 'keep-alive', 'proxy-connection', 'te', 'trailer',
        'transfer-encoding', 'upgrade', 'expect', 'traceparent', 'tracestate', 'baggage', 'x-request-id',
        'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port', 'x-real-ip',
        'forwarded', 'x-runr-replay', 'k-proxy-request', 'k-original-host', 'accept-encoding',
    ];

    private const HOP_BY_HOP = ['connection', 'keep-alive', 'proxy-connection', 'te', 'trailer', 'transfer-encoding', 'upgrade'];

    public function __construct(private ReplayTokens $tokens) {}

    // ── Ingest ───────────────────────────────────────────────────────────────────────────────

    /**
     * A capture POSTed by a function pod (RUNR_REPLAY_URL / RUNR_REPLAY_TOKEN).
     */
    public function ingest(string $key, ?string $bearer, array $payload): string
    {
        $parsed = ReplayTokens::parseKey($key);
        if (! $parsed) {
            return self::INGEST_INVALID;
        }
        [$tenantId, $projectId] = $parsed;

        return TenantScope::run($tenantId, function () use ($key, $bearer, $payload, $projectId) {
            $project = Project::query()->find($projectId);
            if (! $project) {
                return self::INGEST_UNAUTHORIZED;
            }

            $settings = ProjectObservabilitySetting::forProject($project);
            if (! $this->tokens->verify($key, $settings, $bearer)) {
                return self::INGEST_UNAUTHORIZED;
            }

            $this->store($project, $settings, $payload);

            return self::INGEST_STORED;
        }) ?? self::INGEST_UNAUTHORIZED;
    }

    /**
     * Store a capture with the project's redaction rules and limits applied again.
     */
    public function store(Project $project, ProjectObservabilitySetting $settings, array $p): ReplayCapture
    {
        $redactHeaders = array_merge($settings->redactedHeaders(), self::HOP_BY_HOP);
        $max = max(0, (int) $settings->replay_max_body_bytes);

        [$headers, $removed] = self::redactHeaders((array) ($p['headers'] ?? []), $redactHeaders);
        [$respHeaders] = self::redactHeaders((array) ($p['response_headers'] ?? []), $redactHeaders);
        $removed = array_values(array_unique(array_merge(
            $removed,
            array_map(fn ($h) => strtolower((string) $h), array_filter((array) ($p['redacted_headers'] ?? []), 'is_string')),
        )));
        sort($removed);

        [$body, $bodyB64, $bodyCut] = self::limitBody((string) ($p['body'] ?? ''), (bool) ($p['body_base64'] ?? false), $max);
        [$resp, $respB64, $respCut] = self::limitBody((string) ($p['response_body'] ?? ''), (bool) ($p['response_body_base64'] ?? false), $max);

        $method = strtoupper((string) ($p['method'] ?? 'GET'));
        if (! preg_match('/^[A-Z]{1,16}$/', $method)) {
            $method = 'GET';
        }
        $path = (string) ($p['path'] ?? '/');
        $path = '/'.ltrim(mb_substr($path, 0, 4096), '/');

        $status = (int) ($p['status'] ?? 500);
        $trace = strtolower((string) ($p['trace_id'] ?? ''));

        try {
            $at = isset($p['captured_at']) ? Carbon::parse((string) $p['captured_at']) : now();
        } catch (\Throwable) {
            $at = now();
        }
        if ($at->isFuture()) {
            $at = now();
        }

        $capture = ReplayCapture::query()->create([
            'project_id' => $project->getKey(),
            'captured_at' => $at,
            'expires_at' => now()->addDays(max(1, (int) $settings->replay_retention_days)),
            'trigger' => in_array($p['trigger'] ?? 'http', ['http', 'queue'], true) ? $p['trigger'] : 'http',
            'request_id' => isset($p['request_id']) ? mb_substr((string) $p['request_id'], 0, 128) : null,
            'trace_id' => preg_match('/^[0-9a-f]{32}$/', $trace) ? $trace : null,
            'revision' => isset($p['revision']) ? mb_substr((string) $p['revision'], 0, 128) : null,
            'method' => $method,
            'path' => $path,
            'query' => self::redactQuery(mb_substr((string) ($p['query'] ?? ''), 0, 8192), $settings->redactedQuery()),
            'headers' => $headers,
            'redacted_headers' => $removed,
            'body' => $body,
            'body_base64' => $bodyB64,
            'body_size' => max(0, (int) ($p['body_size'] ?? strlen($body))),
            'body_truncated' => $bodyCut || (bool) ($p['body_truncated'] ?? false),
            'status' => $status >= 100 && $status <= 999 ? $status : 500,
            'timed_out' => (bool) ($p['timed_out'] ?? false),
            'duration_ms' => isset($p['duration_ms']) ? (float) $p['duration_ms'] : null,
            'response_headers' => $respHeaders,
            'response_body' => $resp,
            'response_body_base64' => $respB64,
            'response_truncated' => $respCut || (bool) ($p['response_body_truncated'] ?? false),
        ]);

        $this->enforceLimit($project->getKey());

        return $capture;
    }

    /** Oldest captures beyond the per-project limit go. */
    private function enforceLimit(string $projectId): void
    {
        $max = max(1, (int) config('observability.replay.max_captures', 500));

        $ids = ReplayCapture::query()->where('project_id', $projectId)
            ->orderByDesc('captured_at')->orderByDesc('id')
            ->skip($max)->take(1000)->pluck('id');

        if ($ids->isNotEmpty()) {
            ReplayCapture::query()->whereIn('id', $ids)->delete();
        }
    }

    // ── Replay ───────────────────────────────────────────────────────────────────────────────

    /**
     * Re-send a capture to the project's current revision. $overrides: headers (e.g. a credential
     * that was redacted — used for this request only, never stored) and query.
     *
     * @throws ReplayNotPossibleException
     */
    public function replay(Project $project, ReplayCapture $capture, array $overrides = []): array
    {
        $base = $this->targetUrl($project);
        if ($base === null) {
            throw new ReplayNotPossibleException(__('observability.replay.no_endpoint'));
        }

        $query = array_key_exists('query', $overrides) ? (string) $overrides['query'] : (string) $capture->query;
        $url = $base.'/'.ltrim($capture->path, '/').($query !== '' ? '?'.$query : '');

        $headers = [];
        foreach ($capture->headers ?? [] as $name => $value) {
            if (! in_array(strtolower((string) $name), self::NOT_REPLAYED, true)) {
                $headers[(string) $name] = (string) $value;
            }
        }
        foreach ((array) ($overrides['headers'] ?? []) as $name => $value) {
            $name = trim((string) $name);
            if ($name !== '' && ! in_array(strtolower($name), self::NOT_REPLAYED, true)) {
                $headers[$name] = (string) $value;
            }
        }
        $headers['X-RunR-Replay'] = $capture->id;

        $body = (string) ($capture->body ?? '');
        if ($capture->body_base64) {
            $body = (string) base64_decode($body, true);
        }

        $request = Http::withOptions([
            'verify' => (bool) config('observability.replay.verify_tls', true),
            'allow_redirects' => false,
            'http_errors' => false,
        ])->timeout(max(1, (int) config('observability.replay.timeout', 30)))
            ->withHeaders($headers);

        if ($body !== '') {
            $request = $request->withBody($body, $headers['content-type'] ?? $headers['Content-Type'] ?? 'application/octet-stream');
        }

        $started = microtime(true);
        try {
            $response = $request->send($capture->method, $url);
            $settings = ProjectObservabilitySetting::forProject($project);
            [$respHeaders] = self::redactHeaders(array_map(fn ($v) => implode(', ', (array) $v), $response->headers()), $settings->redactedHeaders());
            [$respBody, $respB64, $respCut] = self::limitBody($response->body(), false, max(1, (int) $settings->replay_max_body_bytes));
            $result = [
                'status' => $response->status(),
                'headers' => $respHeaders,
                'body' => $respBody,
                'body_base64' => $respB64,
                'truncated' => $respCut,
                'error' => null,
            ];
        } catch (ConnectionException $e) {
            $result = ['status' => 0, 'headers' => [], 'body' => '', 'body_base64' => false, 'truncated' => false, 'error' => mb_substr($e->getMessage(), 0, 500)];
        }

        $result += [
            'duration_ms' => round((microtime(true) - $started) * 1000, 1),
            'replayed_at' => now()->toIso8601String(),
            'url' => preg_replace('/\?.*$/', '', $url),
            'version' => $project->activeDeployment?->version?->number,
        ];

        $original = [
            'status' => $capture->status,
            'headers' => $capture->response_headers ?? [],
            'body' => $capture->response_body,
            'body_base64' => $capture->response_body_base64,
        ];
        $diff = ResponseDiff::compare($original, $result);

        $capture->forceFill([
            'last_replay' => $result + ['changed' => $diff['changed']],
            'replay_count' => $capture->replay_count + 1,
            'last_replayed_at' => now(),
        ])->save();

        return [
            'capture' => $capture->toSummary(),
            'original' => $original + ['truncated' => $capture->response_truncated],
            'replay' => $result,
            'diff' => $diff,
        ];
    }

    /**
     * The project's own HTTP endpoint (https://<slug>.<knative domain>) — routes to the latest
     * ready revision. Null for queue / schedule projects (no external endpoint).
     */
    public function targetUrl(Project $project): ?string
    {
        if (! app(ProjectTriggers::class)->plan($project)->has('http')) {
            return null;
        }

        $region = $project->knativeRegion;
        if (! $region) {
            return null;
        }

        $domain = $region->effective_settings['knative_domain'] ?? null;
        if (! $domain) {
            $base = Setting::get('base_domain');
            if (! $base) {
                return null;
            }
            $domain = "{$region->name}.{$base}";
        }

        $scheme = (string) config('observability.replay.target_scheme', 'https') === 'http' ? 'http' : 'https';

        return "{$scheme}://{$project->slug}.{$domain}";
    }

    /** Expired captures of every scope. */
    public function prune(): int
    {
        $deleted = 0;
        TenantScope::each(function () use (&$deleted) {
            $deleted += ReplayCapture::query()->where('expires_at', '<', now())->delete();
        });

        return $deleted;
    }

    // ── Redaction helpers (the wrapper applies the same rules in the pod) ─────────────────────

    /**
     * @param  array<string, mixed>  $headers
     * @param  list<string>  $redact  lower-case names
     * @return array{0: array<string, string>, 1: list<string>}
     */
    public static function redactHeaders(array $headers, array $redact): array
    {
        $out = [];
        $removed = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower(trim((string) $name));
            if ($lower === '') {
                continue;
            }
            // the configured names, and every name SecretRedactor treats as a secret (x-api-key, x-auth-token …)
            if (in_array($lower, $redact, true) || SecretRedactor::isSecretKey(str_replace('-', '_', $lower))) {
                $removed[] = $lower;

                continue;
            }
            $out[$lower] = mb_substr(is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value, 0, 8192);
        }
        ksort($out);

        return [$out, array_values(array_unique($removed))];
    }

    /** Values of redacted query parameters replaced by REDACTED, order and encoding kept. */
    public static function redactQuery(string $query, array $redact): string
    {
        if ($query === '') {
            return '';
        }

        $parts = explode('&', $query);
        foreach ($parts as $i => $part) {
            if (! str_contains($part, '=')) {
                continue;
            }
            [$k] = explode('=', $part, 2);
            $name = strtolower(urldecode($k));
            if (in_array($name, $redact, true) || SecretRedactor::isSecretKey(str_replace('-', '_', $name))) {
                $parts[$i] = $k.'=REDACTED';
            }
        }

        return implode('&', $parts);
    }

    /**
     * @return array{0: string, 1: bool, 2: bool} [body, base64, truncated]
     */
    public static function limitBody(string $body, bool $base64, int $max): array
    {
        $raw = $base64 ? base64_decode($body, true) : $body;
        if ($raw === false) {
            return ['', false, true];
        }

        $cut = strlen($raw) > $max;
        if ($cut) {
            $raw = substr($raw, 0, $max);
        }

        if (mb_check_encoding($raw, 'UTF-8')) {
            return [$raw, false, $cut];
        }

        return [base64_encode($raw), true, $cut];
    }
}
