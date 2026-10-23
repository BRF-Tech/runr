<?php

namespace App\Jobs\OrgTransfer;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Jobs\Concerns\RunsOnLongQueue;
use App\Models\OrgTransfer;
use App\Models\User;
use App\Services\OrgTransfer\ImportChoices;
use App\Services\OrgTransfer\OrgImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Applies an import (docs/org-export.md#import) as the member who started it. Encrypted queue
 * payload: it carries the passphrase of the package's secrets, which exists nowhere else. One
 * attempt: a failed apply is resumed by applying again (each entry is created once).
 */
class ApplyImportJob implements ShouldBeEncrypted, ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, RunsOnLongQueue;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public string $transferId, public ?string $passphrase, ?string $initiatorId = null)
    {
        $this->initiatorId = $initiatorId;
        $this->timeout = (int) config('org_transfer.job_timeout', 4 * 3600);
        $this->onLongQueue();
    }

    public function handle(OrgImporter $importer): void
    {
        $transfer = OrgTransfer::query()->find($this->transferId);
        if (! $transfer || $transfer->status !== OrgTransfer::STATUS_APPLYING) {
            return;
        }

        $user = $this->initiatorId !== null ? User::query()->find($this->initiatorId) : null;
        $choices = ImportChoices::from((array) $transfer->options)->withPassphrase($this->passphrase);
        $importer->apply($transfer, $choices, $user);

        $transfer->refresh();
        $payload = ['transfer_id' => $transfer->id, 'kind' => $transfer->kind, 'status' => $transfer->status];
        if ($transfer->status === OrgTransfer::STATUS_COMPLETED) {
            $this->broadcastSuccess('org_transfer.completed', $payload, __('org_transfer.notify.import_done'), $transfer->package_source['organization'] ?? null);
        } elseif ($transfer->status === OrgTransfer::STATUS_FAILED) {
            $this->broadcastFailure('org_transfer.failed', $payload, new \RuntimeException((string) $transfer->message), __('org_transfer.notify.import_failed'));
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('Organization import failed', ['transfer' => $this->transferId, 'error' => $e?->getMessage()]);

        $transfer = OrgTransfer::query()->find($this->transferId);
        if ($transfer && $transfer->status === OrgTransfer::STATUS_APPLYING) {
            $transfer->update(['status' => OrgTransfer::STATUS_FAILED, 'message' => mb_substr((string) $e?->getMessage(), 0, 1000), 'finished_at' => now()]);
        }

        $this->broadcastFailure('org_transfer.failed', ['transfer_id' => $this->transferId, 'kind' => OrgTransfer::KIND_IMPORT, 'status' => OrgTransfer::STATUS_FAILED],
            $e ?? new \RuntimeException('import failed'), __('org_transfer.notify.import_failed'));
    }
}
