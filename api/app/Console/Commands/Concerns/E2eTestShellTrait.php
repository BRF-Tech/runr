<?php

namespace App\Console\Commands\Concerns;

/**
 * Shell (exec) E2E tests — validates the interactive debug shell feature.
 *
 * Tests:
 *  1. exec-session      — POST /exec returns session_id + pod
 *  2. exec-ws-shell     — WS connect, send echo command, verify output
 *  3. exec-token-replay — Consumed token rejected on reuse
 */
trait E2eTestShellTrait
{
    private function runShellTests(array $standardTests, array &$allTests): void
    {
        $this->info('');
        $this->info('── Shell (Exec) Tests ──');

        $candidate = $this->findShellCandidate($standardTests);
        if (! $candidate) {
            $this->warn('  No active deployment — skipping shell tests.');

            return;
        }

        $projectId = $candidate['projectId'];
        $deploymentId = $this->getActiveDeploymentId($projectId);

        if (! $deploymentId) {
            $this->warn('  No active deployment for shell — skipping.');

            return;
        }

        $this->line("  Project: {$candidate['slug']}");

        // Test 1: Exec session
        $t1 = $this->shellEntry('exec-session');
        $session = $this->testExecSession($projectId, $deploymentId, $t1);
        $allTests[] = $this->finishTest($t1);
        $this->shellLog($t1);

        if (! $session) {
            $allTests[] = $this->shellSkipped('exec-ws-shell');
            $allTests[] = $this->shellSkipped('exec-token-replay');

            return;
        }

        // Test 2: WS connect + command
        $t2 = $this->shellEntry('exec-ws-shell');
        $this->testWsShell($session['session_id'], $t2);
        $allTests[] = $this->finishTest($t2);
        $this->shellLog($t2);

        // Test 3: Token replay
        $t3 = $this->shellEntry('exec-token-replay');
        $this->testTokenReplay($session['session_id'], $t3);
        $allTests[] = $this->finishTest($t3);
        $this->shellLog($t3);
    }

    private function testExecSession(string $projectId, string $deploymentId, array &$test): ?array
    {
        try {
            $res = $this->api()->timeout(30)->post(
                "/api/projects/{$projectId}/deployments/{$deploymentId}/exec",
                ['cols' => 80, 'rows' => 24]
            );

            if (! $res->successful()) {
                $test['steps']['verify'] = 'Exec: '.($res->json('message') ?? $res->status());

                return null;
            }

            $data = $res->json();
            if (empty($data['session_id'])) {
                $test['steps']['verify'] = 'Exec: missing session_id';

                return null;
            }

            $test['steps']['verify'] = true;

            return $data;
        } catch (\Throwable $e) {
            $test['steps']['verify'] = 'Exec: '.mb_substr($e->getMessage(), 0, 200);

            return null;
        }
    }

    private function testWsShell(string $token, array &$test): void
    {
        $ws = null;
        try {
            $ws = $this->wsUpgrade($token);
            if (! $ws) {
                $test['steps']['verify'] = 'WS: upgrade failed';

                return;
            }

            // The wake phase reports first ({"status":"searching"}, "waking_up", "waiting", …:
            // the function may be scaled to zero), then the PTY phase answers {"connected":true}
            [$connected, $seen] = $this->wsAwaitConnected($ws, 120);
            if ($connected !== true) {
                $test['steps']['verify'] = 'WS: '.($connected ?? 'no connected frame').($seen ? ' (after '.implode(', ', $seen).')' : '');

                return;
            }

            // Drain shell prompt
            usleep(500_000);
            for ($i = 0; $i < 5; $i++) {
                if (! $this->wsReadFrame($ws, 1)) {
                    break;
                }
            }

            // Send echo with unique marker
            $marker = 'RUNR_E2E_'.bin2hex(random_bytes(4));
            $this->wsSendText($ws, json_encode(['input' => base64_encode("echo {$marker}\n")]));
            usleep(1_000_000);

            // Collect output
            $output = '';
            for ($i = 0; $i < 10; $i++) {
                $frame = $this->wsReadFrame($ws, 2);
                if (! $frame) {
                    break;
                }
                $m = json_decode($frame, true);
                if (isset($m['output'])) {
                    $output .= base64_decode($m['output']);
                }
            }

            if (str_contains($output, $marker)) {
                $test['steps']['verify'] = true;
            } else {
                $clean = preg_replace('/[\x00-\x1f\x7f-\xff]/', '.', substr($output, 0, 200));
                $test['steps']['verify'] = "Shell: marker not found in: {$clean}";
            }
        } catch (\Throwable $e) {
            $test['steps']['verify'] = 'Shell: '.mb_substr($e->getMessage(), 0, 200);
        } finally {
            if ($ws) {
                @fclose($ws);
            }
        }
    }

    private function testTokenReplay(string $consumedToken, array &$test): void
    {
        $ws = null;
        try {
            $ws = $this->wsUpgrade($consumedToken);
            if (! $ws) {
                // Connection refused = also acceptable (token already consumed)
                $test['steps']['verify'] = true;

                return;
            }

            $data = $this->wsReadFrame($ws, 5);
            $msg = json_decode($data ?? '', true);

            if (isset($msg['error']) && str_contains($msg['error'], 'not found')) {
                $test['steps']['verify'] = true;
            } else {
                $test['steps']['verify'] = 'Replay: token was accepted (should be rejected)';
            }
        } catch (\Throwable $e) {
            $test['steps']['verify'] = 'Replay: '.mb_substr($e->getMessage(), 0, 200);
        } finally {
            if ($ws) {
                @fclose($ws);
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Frames of a shell session until {"connected":true} or {"error":…}, at most $timeout
     * seconds: [true | the error | null, the wake statuses seen on the way].
     *
     * @return array{0: true|string|null, 1: list<string>}
     */
    private function wsAwaitConnected(mixed $ws, int $timeout): array
    {
        $seen = [];
        $deadline = time() + $timeout;
        while (time() < $deadline) {
            $frame = $this->wsReadFrame($ws, 10);
            if ($frame === null) {
                // no frame within 10 s, or the server closed the connection
                if (feof($ws)) {
                    return ['connection closed', $seen];
                }

                continue;
            }
            [$result, $status] = self::shellFrameOutcome($frame);
            if ($result !== null) {
                return [$result, $seen];
            }
            if ($status !== null) {
                $seen[] = $status;
            }
        }

        return [null, $seen];
    }

    /**
     * One shell WebSocket frame: [true when connected | the error | null, the wake status or null].
     *
     * @return array{0: true|string|null, 1: string|null}
     */
    public static function shellFrameOutcome(string $frame): array
    {
        $msg = json_decode($frame, true);
        if (! is_array($msg)) {
            return [null, null];
        }
        if ($msg['connected'] ?? false) {
            return [true, null];
        }
        if (isset($msg['error'])) {
            return [(string) $msg['error'], null];
        }

        return [null, isset($msg['status']) ? (string) $msg['status'] : null];
    }

    /**
     * Perform WebSocket upgrade handshake, return stream or null. Reverb is reached the way the
     * API's broadcasting reaches it (REVERB_HOST / REVERB_PORT): under docker compose an alias on
     * the stack's own network, not `reverb`, which other stacks on the shared kind network answer too.
     */
    private function wsUpgrade(string $token): mixed
    {
        $options = config('reverb.apps.apps.0.options', []);
        $address = (($options['host'] ?? null) ?: 'reverb').':'.(($options['port'] ?? null) ?: 8080);
        $ws = @stream_socket_client("tcp://{$address}", $errno, $errstr, 5);
        if (! $ws) {
            return null;
        }

        $key = base64_encode(random_bytes(16));
        fwrite($ws, "GET /shell?token={$token} HTTP/1.1\r\n"
            ."Host: {$address}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");

        $resp = '';
        while ($l = fgets($ws, 4096)) {
            $resp .= $l;
            if (trim($l) === '') {
                break;
            }
        }

        if (! str_contains($resp, '101')) {
            @fclose($ws);

            return null;
        }

        return $ws;
    }

    private function findShellCandidate(array $tests): ?array
    {
        foreach ($tests as $t) {
            if ($t['steps']['ready'] !== true) {
                continue;
            }
            if (($t['config']['trigger_type'] ?? '') !== 'http') {
                continue;
            }
            if (! $t['projectId']) {
                continue;
            }

            return $t;
        }

        return null;
    }

    private function getActiveDeploymentId(string $projectId): ?string
    {
        try {
            $res = $this->api()->get("/api/projects/{$projectId}/deployments");
            foreach ($res->json('data') ?? [] as $dep) {
                if (($dep['status'] ?? '') === 'active') {
                    return $dep['id'];
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function shellEntry(string $name): array
    {
        return [
            'name' => $name,
            'config' => ['service_type' => 'shell', 'trigger_type' => 'none'],
            'regionId' => null,
            'projectId' => null,
            'slug' => null,
            'versionId' => null,
            'steps' => ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => null],
            'startTime' => microtime(true),
            'duration' => null,
        ];
    }

    private function shellSkipped(string $name): array
    {
        $t = $this->shellEntry($name);
        $t['steps']['verify'] = 'skipped';

        return $t;
    }

    private function shellLog(array $test): void
    {
        $icon = $test['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $suffix = is_string($test['steps']['verify']) && $test['steps']['verify'] !== 'skipped'
            ? " — {$test['steps']['verify']}" : '';
        $this->line("  {$icon} {$test['name']}{$suffix}");
    }
}
