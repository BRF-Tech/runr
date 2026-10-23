<?php

namespace App\Jobs\OrgTransfer;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Jobs\Concerns\RunsOnLongQueue;
use App\Models\OrgTransfer;
use App\Services\OrgTransfer\ImportChoices;
use App\Services\OrgTransfer\OrgImporter;
use App\Services\OrgTransfer\Package\PackageException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * An uploaded package is unpacked, checked against its manifest and planned with the default
 * choices (docs/org-export.md#the-plan): the transfer is then `planned` (the plan the console
 * shows) or `failed` with why (not a package, a newer schema version, a checksum that does not
 * match).
 */
class VerifyImportPackageJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, RunsOnLongQueue;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public string $transferId, ?string $initiatorId = null)
    {
        $this->initiatorId = $initiatorId;
        $this->timeout = (int) config('org_transfer.job_timeout', 4 * 3600);
        $this->onLongQueue();
    }

    public function handle(OrgImporter $importer): void
    {
        $transfer = OrgTransfer::query()->find($this->transferId);
        if (! $transfer || $transfer->status !== OrgTransfer::STATUS_VERIFYING) {
            return;
        }

        try {
            $importer->verify($transfer, ImportChoices::from((array) $transfer->options));
        } catch (PackageException $e) {
            // recorded on the transfer (failed, with the reason) and audited
            $this->broadcastFailure('org_transfer.failed', ['transfer_id' => $transfer->id, 'kind' => $transfer->kind, 'status' => OrgTransfer::STATUS_FAILED, 'code' => $e->errorCode],
                $e, __('org_transfer.notify.import_rejected'));
        }
    }

    public function failed(?\Throwable $e): void
    {
        $transfer = OrgTransfer::query()->find($this->transferId);
        if ($transfer && $transfer->status === OrgTransfer::STATUS_VERIFYING) {
            $transfer->update(['status' => OrgTransfer::STATUS_FAILED, 'message' => mb_substr((string) $e?->getMessage(), 0, 1000), 'finished_at' => now()]);
        }
    }
}
