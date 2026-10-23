<?php

namespace App\Services\OrgTransfer;

use App\Events\PlatformEvent;
use App\Models\OrgTransfer;
use Illuminate\Support\Facades\Log;

/**
 * Saves a transfer's status / phase / progress and tells the console (docs/org-export.md):
 * `org_transfer.progress` on the organization's platform channel — at every status or phase
 * change and at most every BROADCAST_SECONDS in between. The payload carries progress only (no
 * plan, no report, never a secret); clients load the transfer for the rest.
 */
class OrgTransferProgress
{
    private const BROADCAST_SECONDS = 1.5;

    /** @var array<string, array{at: float, status: ?string, phase: ?string}> */
    private array $sent = [];

    /** @param array<string, mixed> $attributes */
    public function update(OrgTransfer $transfer, array $attributes): void
    {
        if (isset($attributes['progress'])) {
            $attributes['progress'] = max(0, min(100, (int) $attributes['progress']));
        }
        if (isset($attributes['message']) && is_string($attributes['message'])) {
            $attributes['message'] = mb_substr($attributes['message'], 0, 2000);
        }

        $transfer->forceFill($attributes)->save();
        $this->broadcast($transfer);
    }

    /** Progress inside a phase: $done of $total mapped onto [$from, $to] percent. */
    public function step(OrgTransfer $transfer, string $phase, int $from, int $to, int|float $done, int|float $total, ?string $message = null): void
    {
        $share = $total > 0 ? min(1, max(0, $done / $total)) : 1;
        $this->update($transfer, array_filter([
            'phase' => $phase,
            'progress' => (int) floor($from + ($to - $from) * $share),
            'message' => $message,
        ], fn ($v) => $v !== null));
    }

    private function broadcast(OrgTransfer $transfer): void
    {
        $last = $this->sent[$transfer->id] ?? null;
        $now = microtime(true);
        $changed = $last === null || $last['status'] !== $transfer->status || $last['phase'] !== $transfer->phase;
        if (! $changed && ($now - $last['at']) < self::BROADCAST_SECONDS) {
            return;
        }
        $this->sent[$transfer->id] = ['at' => $now, 'status' => $transfer->status, 'phase' => $transfer->phase];

        try {
            event(new PlatformEvent('org_transfer.progress', $transfer->progressPayload()));
        } catch (\Throwable $e) {
            // The progress is saved; the live push is best effort
            Log::warning('Org transfer progress not broadcast', ['transfer' => $transfer->id, 'error' => $e->getMessage()]);
        }
    }
}
