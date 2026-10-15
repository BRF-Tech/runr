<?php

namespace Modules\Ticket\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Ticket\Console\Concerns\TicketE2eApiTrait;
use Modules\Ticket\Console\Concerns\TicketE2eWsTrait;

class TicketE2eCommand extends Command
{
    use TicketE2eApiTrait;
    use TicketE2eWsTrait;

    protected $signature = 'test:e2e-ticket
        {--base-url=http://localhost:8000 : API base URL}';

    protected $description = 'Run Ticket module e2e tests — CRUD, admin ops, WS event channels';

    protected string $baseUrl;

    protected string $adminToken = '';

    protected string $userToken = '';

    protected ?int $ticketId = null;

    public function handle(): int
    {
        if (! config('ticket.enabled')) {
            $this->error('TICKET_ENABLED is not set to true.');

            return self::FAILURE;
        }

        $this->baseUrl = rtrim($this->option('base-url'), '/');

        if (! $this->checkHealth()) {
            return self::FAILURE;
        }

        $this->info('Running prerequisites...');
        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--force' => true]);

        $this->adminToken = $this->doLogin(config('app.admin.username'), (string) config('app.admin.password'));
        if (! $this->adminToken) {
            return self::FAILURE;
        }

        Http::post("{$this->baseUrl}/api/auth/register", [
            'name' => 'Test User', 'username' => 'testuser',
            'email' => 'test@test.com', 'password' => 'test1234',
            'password_confirmation' => 'test1234',
        ]);

        $this->userToken = $this->doLogin('testuser', 'test1234');
        if (! $this->userToken) {
            return self::FAILURE;
        }

        $startTime = microtime(true);
        $results = [];

        $this->runApiTests($results);
        $this->runWsTests($results);

        $this->printResults($results, $startTime);
        $this->saveReport($results, $startTime);

        $failed = count(array_filter($results, fn ($r) => ! $r['passed']));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function checkHealth(): bool
    {
        try {
            $r = Http::timeout(10)->get("{$this->baseUrl}/up");
            if ($r->successful()) {
                $this->info('API reachable.');

                return true;
            }
        } catch (\Throwable $e) {
            $this->error("API not reachable: {$e->getMessage()}");
        }

        return false;
    }

    protected function doLogin(string $user, string $pass): string
    {
        try {
            $r = Http::post("{$this->baseUrl}/api/auth/login", ['login' => $user, 'password' => $pass]);
            $token = $r->json('token') ?? '';
            if ($token) {
                $this->info("Logged in as {$user}.");

                return $token;
            }
        } catch (\Throwable) {
        }
        $this->error("Login failed for {$user}.");

        return '';
    }

    protected function api(?string $token = null): PendingRequest
    {
        return Http::withToken($token ?? $this->adminToken)->accept('application/json')->baseUrl($this->baseUrl);
    }

    protected function runTest(string $label, callable $fn): array
    {
        $start = microtime(true);
        try {
            $fn();

            return ['label' => $label, 'passed' => true, 'message' => '', 'time' => microtime(true) - $start];
        } catch (\Throwable $e) {
            return ['label' => $label, 'passed' => false, 'message' => $e->getMessage(), 'time' => microtime(true) - $start];
        }
    }

    protected function assert(bool $cond, string $msg): void
    {
        if (! $cond) {
            throw new \RuntimeException($msg);
        }
    }

    private function printResults(array $results, float $startTime): void
    {
        $this->info('');
        $this->info('── Ticket E2E Results ──');
        $maxLen = max(array_map(fn ($r) => strlen($r['label']), $results));
        foreach ($results as $r) {
            $icon = $r['passed'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $dur = round($r['time'], 1).'s';
            $line = "  {$icon} ".str_pad($r['label'], $maxLen + 2).$dur;
            if (! $r['passed']) {
                $line .= "  <fg=red>{$r['message']}</>";
            }
            $this->line($line);
        }
        $passed = count(array_filter($results, fn ($r) => $r['passed']));
        $total = count($results);
        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info('');
        $this->info("  Passed: {$passed}/{$total}  Duration: {$elapsed}s");
    }

    private function saveReport(array $results, float $startTime): void
    {
        $ts = date('Ymd-His');
        $date = date('Y-m-d H:i:s');
        $passed = count(array_filter($results, fn ($r) => $r['passed']));
        $total = count($results);
        $elapsed = round(microtime(true) - $startTime, 1);
        $md = "# Ticket E2E Results — {$date}\n\n";
        $md .= "**Passed:** {$passed}/{$total}  **Duration:** {$elapsed}s\n\n";
        $md .= "| Test | Result | Time | Message |\n|------|--------|------|---------|\n";
        foreach ($results as $r) {
            $icon = $r['passed'] ? '✓' : '✗';
            $md .= "| {$r['label']} | {$icon} | ".round($r['time'], 1).'s | '.($r['message'] ?: '—')." |\n";
        }
        $path = storage_path("e2e-ticket-{$ts}.md");
        file_put_contents($path, $md);
        $this->info("  Report: {$path}");
    }
}
