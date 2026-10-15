<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditLogger;
use App\Support\Secrets\SecretColumnEncryptor;
use App\Support\Secrets\SecretColumns;
use Illuminate\Console\Command;
use Modules\Tenancy\Models\Tenant;

/**
 * Encryption at rest, on demand (docs/security.md):
 *
 *   php artisan secrets:encrypt            plaintext secret values → encrypted with APP_KEY
 *   php artisan secrets:encrypt --rotate   also re-encrypt values written with a previous key
 *                                          (APP_PREVIOUS_KEYS) — step 2 of a key rotation
 *
 * Central schema and, with the Tenancy module, every tenant schema. Idempotent.
 */
class EncryptSecretsCommand extends Command
{
    protected $signature = 'secrets:encrypt
        {--rotate : Re-encrypt values encrypted with a previous key (APP_PREVIOUS_KEYS) with APP_KEY}
        {--tenant= : Only this tenant (slug); the central schema is skipped}';

    protected $description = 'Encrypt plaintext secret columns and, with --rotate, re-encrypt them with the current APP_KEY';

    public function handle(): int
    {
        $rotate = (bool) $this->option('rotate');
        $only = $this->option('tenant');
        $totals = ['encrypted' => 0, 'rotated' => 0];
        $failed = 0;

        if (! $only) {
            $counts = (new SecretColumnEncryptor)->encrypt(SecretColumns::central(), $rotate);
            $this->report('central', $counts, $totals);
        }

        foreach ($this->tenants($only) as $tenant) {
            $slug = (string) $tenant->getAttribute('slug');
            try {
                $counts = $tenant->run(fn () => (new SecretColumnEncryptor)->encrypt(SecretColumns::tenant(), $rotate));
                $this->report("tenant {$slug}", $counts, $totals);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("tenant {$slug}: {$e->getMessage()}");
            }
        }

        app(AuditLogger::class)->record('secrets.reencrypted', [
            'actor' => 'system',
            'tenant_id' => null,
            'result' => $failed ? 'failure' : 'success',
            'metadata' => $totals + ['rotate' => $rotate, 'failed_tenants' => $failed],
        ]);

        $this->info("Encrypted {$totals['encrypted']} plaintext value(s), re-encrypted {$totals['rotated']} with the current key.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function report(string $scope, array $counts, array &$totals): void
    {
        $totals['encrypted'] += $counts['encrypted'];
        $totals['rotated'] += $counts['rotated'];
        $this->line("  {$scope}: {$counts['encrypted']} encrypted, {$counts['rotated']} re-encrypted");
    }

    /** @return iterable<int, Tenant> */
    private function tenants(?string $slug): iterable
    {
        $class = 'Modules\\Tenancy\\Models\\Tenant';
        if (! config('tenancy.enabled') || ! class_exists($class)) {
            return [];
        }

        /** @var iterable<int, Tenant> $tenants */
        $tenants = $slug ? $class::where('slug', $slug)->get() : $class::all();

        return $tenants;
    }
}
