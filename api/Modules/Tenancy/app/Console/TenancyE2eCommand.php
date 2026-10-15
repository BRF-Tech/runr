<?php

namespace Modules\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Modules\Tenancy\Console\Concerns\TenancyE2eAppCasesTrait;
use Modules\Tenancy\Console\Concerns\TenancyE2eHelperTrait;
use Modules\Tenancy\Console\Concerns\TenancyE2eMigrationCasesTrait;
use Modules\Tenancy\Console\Concerns\TenancyE2eRegionCasesTrait;
use Modules\Tenancy\Console\Concerns\TenancyE2eTenantCasesTrait;

class TenancyE2eCommand extends Command
{
    use TenancyE2eAppCasesTrait;
    use TenancyE2eHelperTrait;
    use TenancyE2eMigrationCasesTrait;
    use TenancyE2eRegionCasesTrait;
    use TenancyE2eTenantCasesTrait;

    protected $signature = 'test:e2e-tenancy
        {--base-url=http://localhost:8000 : API base URL}';

    protected $description = 'Run tenancy e2e tests — tenant CRUD, regions, quotas, projects, isolation';

    // Auth
    protected string $baseUrl;

    protected string $adminToken = '';

    protected string $userToken = '';

    // Shared test state
    protected ?string $tenantAId = null;

    protected ?string $tenantBId = null;

    protected ?string $managedRegionId = null;

    protected ?string $infiniteRegionId = null;

    protected ?int $testUserId = null;

    protected mixed $projectId = null;

    public function handle(): int
    {
        if (! config('tenancy.enabled')) {
            $this->error('TENANCY_ENABLED is not set to true.');

            return self::FAILURE;
        }

        $this->baseUrl = rtrim($this->option('base-url'), '/');

        if (! $this->checkHealth()) {
            return self::FAILURE;
        }

        $this->info('Running prerequisites...');
        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--force' => true]);
        $this->seedExecutorImage();

        $this->adminToken = $this->doLogin(config('app.admin.username'), (string) config('app.admin.password'));
        if (! $this->adminToken) {
            return self::FAILURE;
        }

        $this->userToken = $this->doLogin('test', 'test123');
        if (! $this->userToken) {
            return self::FAILURE;
        }

        $startTime = microtime(true);
        $results = [];

        $this->runTenantTests($results);
        $this->runRegionTests($results);
        $this->runAppTests($results);
        $this->runMigrationTests($results);
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
        $this->info('── Tenancy E2E Results ──');

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

        $md = "# Tenancy E2E Results — {$date}\n\n";
        $md .= "**Passed:** {$passed}/{$total}  **Duration:** {$elapsed}s\n\n";
        $md .= "| Test | Result | Time | Message |\n|------|--------|------|---------|\n";

        foreach ($results as $r) {
            $icon = $r['passed'] ? '✓' : '✗';
            $dur = round($r['time'], 1).'s';
            $msg = $r['message'] ?: '—';
            $md .= "| {$r['label']} | {$icon} | {$dur} | {$msg} |\n";
        }

        $path = storage_path("e2e-tenancy-{$ts}.md");
        file_put_contents($path, $md);
        $this->info("  Report: {$path}");
    }
}
