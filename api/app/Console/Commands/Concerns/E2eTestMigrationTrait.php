<?php

namespace App\Console\Commands\Concerns;

use App\Models\KnativeRegion;

trait E2eTestMigrationTrait
{
    private function runMigrationTests(string $regionId, array &$tests): void
    {
        $this->info('');
        $this->info('── Migration Tests ──');

        $tests[] = $this->runMigrationTest('Cluster Migration', function () use ($regionId) {
            $this->testClusterMigration($regionId);
        });

        $tests[] = $this->runMigrationTest('Domain Migration', function () use ($regionId) {
            $this->testDomainMigration($regionId);
        });

        $tests[] = $this->runMigrationTest('Migration Status API', function () use ($regionId) {
            $this->testMigrationStatusApi($regionId);
        });
    }

    private function testClusterMigration(string $regionId): void
    {
        $region = KnativeRegion::find($regionId);
        $this->e2eMigrationAssert((bool) $region, 'Region not found');

        // Create a test project
        $project = $this->createMigrationTestProject($regionId);
        $this->e2eMigrationAssert((bool) $project, 'Project creation failed');

        // Start migration with the same kubeconfig (simulating migration)
        $response = $this->api()->post("/api/knative-regions/{$regionId}/migrate", [
            'kubeconfig' => $region->kubeconfig,
        ]);
        $this->e2eMigrationAssert($response->successful(), "Migrate start: {$response->status()} — {$response->body()}");

        // Poll migration status. The migration installs the region's registry, Redis and sandbox
        // runtimes on the target (Helm upgrades that wait for their pods, up to 10 min each) and
        // redeploys every project: minutes, not seconds (MigrateRegionJob and MigrateRegionProjectsJob allow 30 min each)
        $started = time();
        $deadline = $started + 900;
        $status = null;
        while (time() < $deadline) {
            $r = $this->api()->get("/api/knative-regions/{$regionId}/migration-status");
            $status = $r->json('migration_status');
            if (in_array($status, ['dns_check', 'completed', 'failed'])) {
                break;
            }
            sleep(5);
        }
        $this->line('    migration: '.($status ?? 'no status').' after '.(time() - $started).' s');

        $this->e2eMigrationAssert(
            in_array($status, ['dns_check', 'completed']),
            "Expected dns_check or completed, got: {$status}"
        );

        // Force complete
        $response = $this->api()->post("/api/knative-regions/{$regionId}/complete-migration");
        $this->e2eMigrationAssert($response->successful(), "Complete: {$response->status()}");

        // Verify region is no longer migrating
        $region->refresh();
        $this->e2eMigrationAssert($region->migration_status === 'completed', 'Status not completed');

        // Clean up project
        $this->api()->delete("/api/projects/{$project}");
    }

    private function testDomainMigration(string $regionId): void
    {
        // Set initial base_domain
        $r = $this->api()->patch('/api/settings', ['base_domain' => 'old-test.example.com']);
        $this->e2eMigrationAssert($r->successful(), "Set old domain: {$r->status()}");

        // Change domain — triggers migration
        $r = $this->api()->patch('/api/settings', ['base_domain' => 'new-test.example.com']);
        $this->e2eMigrationAssert($r->successful(), "Set new domain: {$r->status()}");

        // Verify region has domain migration status
        $region = KnativeRegion::find($regionId);
        $this->e2eMigrationAssert(
            $region->domain_migration_status === 'dns_check',
            "Expected dns_check, got: {$region->domain_migration_status}"
        );

        // Clean up — remove domain
        $r = $this->api()->patch('/api/settings', ['base_domain' => '']);
        $this->e2eMigrationAssert($r->successful(), "Clear domain: {$r->status()}");
    }

    private function testMigrationStatusApi(string $regionId): void
    {
        $r = $this->api()->get("/api/knative-regions/{$regionId}/migration-status");
        $this->e2eMigrationAssert($r->successful(), "Status API: {$r->status()}");

        $data = $r->json();
        $this->e2eMigrationAssert(array_key_exists('migration_status', $data), 'Missing migration_status');
        $this->e2eMigrationAssert(array_key_exists('is_migrating', $data), 'Missing is_migrating');
    }

    private function createMigrationTestProject(string $regionId): ?string
    {
        // The same payload as the deploy tests' projects (config is required)
        $response = $this->api()->post('/api/projects', $this->buildProjectPayload(
            'migration-test-'.substr(md5(uniqid()), 0, 6),
            ['service_type' => 'xaas', 'runtime' => 'python', 'runtime_version' => '3.12', 'trigger_type' => 'http'],
            $regionId,
        ));

        if (! $response->successful()) {
            $this->line("    project create: {$response->status()} — ".mb_substr($response->body(), 0, 300));

            return null;
        }
        $data = $response->json('data') ?? $response->json();

        return $data['id'] ?? null;
    }

    /**
     * One migration check as a result entry of the run (the shape printResultsTable and
     * countResults read, like the shell checks): the outcome in steps.verify.
     */
    private function runMigrationTest(string $label, callable $fn): array
    {
        $entry = [
            'name' => $label,
            'config' => ['service_type' => 'migration', 'trigger_type' => 'none'],
            'regionId' => null,
            'projectId' => null,
            'slug' => null,
            'versionId' => null,
            'steps' => ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => null],
            'startTime' => microtime(true),
            'duration' => null,
        ];

        try {
            $fn();
            $entry['steps']['verify'] = true;
            $this->line("  <fg=green>✓</> {$label}");
        } catch (\Throwable $e) {
            $entry['steps']['verify'] = $e->getMessage();
            $this->line("  <fg=red>✗</> {$label}: {$e->getMessage()}");
        }

        return $this->finishTest($entry);
    }

    private function e2eMigrationAssert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    }
}
