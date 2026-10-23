<?php

namespace App\Jobs\OrgTransfer;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Jobs\Concerns\RunsOnLongQueue;
use App\Models\OrgTransfer;
use App\Services\OrgTransfer\OrgExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Writes an organization package (docs/org-export.md#export). Encrypted queue payload: it carries
 * the passphrase of the package's secrets and the credentials of an S3 destination — both exist
 * nowhere else. One attempt: an export that failed is started again by the user.
 */
class ExportOrganizationJob implements ShouldBeEncrypted, ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, RunsOnLongQueue;

    public int $tries = 1;

    public int $timeout;

    /** @param array<string, string>|null $credentials */
    public function __construct(
        public string $transferId,
        public ?string $passphrase,
        public ?array $credentials,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
        $this->timeout = (int) config('org_transfer.job_timeout', 4 * 3600);
        $this->onLongQueue();
    }

    public function handle(OrgExporter $exporter): void
    {
        $transfer = OrgTransfer::query()->find($this->transferId);
        if (! $transfer || $transfer->status !== OrgTransfer::STATUS_QUEUED) {
            return;
        }

        $exporter->run($transfer, $this->passphrase, $this->credentials);

        $transfer->refresh();
        if ($transfer->status === OrgTransfer::STATUS_COMPLETED) {
            $this->broadcastSuccess('org_transfer.completed', ['transfer_id' => $transfer->id, 'kind' => $transfer->kind, 'status' => $transfer->status],
                __('org_transfer.notify.export_done'), $transfer->package_filename);
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('Organization export failed', ['transfer' => $this->transferId, 'error' => $e?->getMessage()]);

        $transfer = OrgTransfer::query()->find($this->transferId);
        if ($transfer && ! $transfer->isFinished()) {
            $transfer->update(['status' => OrgTransfer::STATUS_FAILED, 'message' => mb_substr((string) $e?->getMessage(), 0, 1000), 'finished_at' => now()]);
        }

        $this->broadcastFailure('org_transfer.failed', ['transfer_id' => $this->transferId, 'kind' => OrgTransfer::KIND_EXPORT, 'status' => OrgTransfer::STATUS_FAILED],
            $e ?? new \RuntimeException('export failed'), __('org_transfer.notify.export_failed'));
    }
}
