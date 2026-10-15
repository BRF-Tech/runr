<?php

namespace Modules\AuthProvider\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Modules\AuthProvider\Console\Concerns\AuthProviderE2eCrudTrait;
use Modules\AuthProvider\Console\Concerns\AuthProviderE2eDiscoverTrait;
use Modules\AuthProvider\Console\Concerns\AuthProviderE2eHelperTrait;
use Modules\AuthProvider\Console\Concerns\AuthProviderE2eSocialTrait;

class AuthProviderE2eCommand extends Command
{
    use AuthProviderE2eCrudTrait;
    use AuthProviderE2eDiscoverTrait;
    use AuthProviderE2eHelperTrait;
    use AuthProviderE2eSocialTrait;

    protected $signature = 'test:e2e-authprovider
        {--base-url=http://localhost:8000 : API base URL}';

    protected $description = 'Run AuthProvider e2e tests — provider CRUD, discover, social accounts, OAuth flow';

    // Auth
    protected string $baseUrl;

    protected string $adminToken = '';

    protected string $userToken = '';

    // Shared test state
    protected ?string $googleProviderId = null;

    protected ?string $githubProviderId = null;

    protected ?string $oidcProviderId = null;

    protected ?string $socialAccountId = null;

    public function handle(): int
    {
        if (! config('authprovider.enabled')) {
            $this->error('AUTH_PROVIDER_ENABLED is not set to true.');

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

        // Register test user (seeder only creates admin)
        Http::post("{$this->baseUrl}/api/auth/register", [
            'name' => 'Test User', 'username' => 'test',
            'email' => 'test@test.com', 'password' => 'test1234',
            'password_confirmation' => 'test1234',
        ]);

        $this->userToken = $this->doLogin('test', 'test1234');
        if (! $this->userToken) {
            return self::FAILURE;
        }

        $startTime = microtime(true);
        $results = [];

        $this->runProviderCrudTests($results);
        $this->runDiscoverTests($results);
        $this->runValidationTests($results);
        $this->runSocialAccountTests($results);
        $this->runOAuthFlowTests($results);
        $this->runCleanupTests($results);

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

    private function doLogin(string $user, string $pass): string
    {
        try {
            $r = Http::post("{$this->baseUrl}/api/auth/login", [
                'login' => $user, 'password' => $pass,
            ]);
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

    private function printResults(array $results, float $startTime): void
    {
        $this->info('');
        $this->info('── AuthProvider E2E Results ──');

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

        $md = "# AuthProvider E2E Results — {$date}\n\n";
        $md .= "**Passed:** {$passed}/{$total}  **Duration:** {$elapsed}s\n\n";
        $md .= "| Test | Result | Time | Message |\n|------|--------|------|---------|\n";

        foreach ($results as $r) {
            $icon = $r['passed'] ? '✓' : '✗';
            $dur = round($r['time'], 1).'s';
            $msg = $r['message'] ?: '—';
            $md .= "| {$r['label']} | {$icon} | {$dur} | {$msg} |\n";
        }

        $path = storage_path("e2e-authprovider-{$ts}.md");
        file_put_contents($path, $md);
        $this->info("  Report: {$path}");
    }
}
