<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Retention of the audit log: events older than retentionDays() are deleted by the daily prune
 * (PruneAuditEventsJob) — the only code path that removes audit rows. The platform setting
 * `audit_retention_days` (central) wins over config('audit.retention_days'); 0 keeps everything.
 */
class AuditRetention
{
    public const SETTING_KEY = 'audit_retention_days';

    public const MAX_DAYS = 3650;

    public function retentionDays(): int
    {
        $stored = $this->settingValue();

        $days = is_numeric($stored) ? (int) $stored : (int) config('audit.retention_days', 365);

        return max(0, min(self::MAX_DAYS, $days));
    }

    public function setRetentionDays(int $days): void
    {
        $days = max(0, min(self::MAX_DAYS, $days));

        $connection = config('database.connections.central') ? 'central' : null;
        Setting::on($connection)->updateOrCreate(['key' => self::SETTING_KEY], ['value' => (string) $days]);
    }

    /** @return int number of deleted events */
    public function prune(): int
    {
        $days = $this->retentionDays();
        if ($days === 0) {
            return 0;
        }

        $cutoff = now()->subDays($days);
        $connection = (new AuditEvent)->getConnectionName();
        $db = DB::connection($connection);
        $deleted = 0;

        AuditEvent::whilePruning(function () use ($db, $cutoff, &$deleted) {
            // Batches keep each transaction (and its row locks) short on a large table
            do {
                $batch = $db->transaction(function () use ($db, $cutoff) {
                    if ($db->getDriverName() === 'pgsql') {
                        // The append-only trigger lets a DELETE through only with this setting
                        $db->statement("SET LOCAL runr.audit_prune = 'on'");
                    }

                    $ids = AuditEvent::query()->where('occurred_at', '<', $cutoff)->orderBy('occurred_at')->limit(1000)->pluck('id');
                    if ($ids->isEmpty()) {
                        return 0;
                    }

                    return AuditEvent::query()->whereKey($ids->all())->delete();
                });
                $deleted += $batch;
            } while ($batch > 0);
        });

        if ($deleted > 0) {
            app(AuditLogger::class)->record('audit.retention.pruned', [
                'actor' => 'system',
                'tenant_id' => null,
                'metadata' => ['deleted' => $deleted, 'retention_days' => $days, 'before' => $cutoff->toISOString()],
            ]);
        }

        return $deleted;
    }

    private function settingValue(): ?string
    {
        try {
            $connection = config('database.connections.central') ? 'central' : null;

            return Setting::on($connection)->whereKey(self::SETTING_KEY)->value('value');
        } catch (\Throwable) {
            return null;
        }
    }
}
