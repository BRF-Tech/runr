<?php

namespace App\Jobs\OrgTransfer;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Jobs\Concerns\RunsOnLongQueue;
use App\Models\AuditEvent;
use App\Models\OrgTransfer;
use App\Services\OrgTransfer\OrgTransferOwner;
use App\Services\OrgTransfer\OrgTransferService;
use App\Services\OrgTransfer\Sources\PackageSources;
use App\Services\OrgTransfer\Sources\SourceException;
use App\Services\OrgTransfer\TransferCancelled;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Reads an import's package from its object binding or S3 bucket (docs/org-export.md#import),
 * then hands it to the verification like a completed upload. Encrypted queue payload: it carries
 * the credentials of an S3 source, which exist nowhere else.
 *
 * Resumable: every attempt continues after the bytes the import holds. A retryable failure (the
 * network, the region) is tried again after a pause; one that is not (no such object, access
 * denied, too large) fails the import at once. A failed fetch keeps its bytes:
 * POST /org-transfers/{id}/fetch resumes it (with the S3 credentials again).
 */
class FetchImportPackageJob implements ShouldBeEncrypted, ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, RunsOnLongQueue;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout;

    /** @param array<string, string>|null $credentials */
    public function __construct(public string $transferId, public ?array $credentials = null, ?string $initiatorId = null)
    {
        $this->initiatorId = $initiatorId;
        $this->timeout = (int) config('org_transfer.job_timeout', 4 * 3600);
        $this->onLongQueue();
    }

    public function handle(PackageSources $sources, OrgTransferService $transfers): void
    {
        $transfer = OrgTransfer::query()->find($this->transferId);
        if (! $transfer || $transfer->status !== OrgTransfer::STATUS_FETCHING) {
            return;
        }

        try {
            $sources->fetch($transfer, $this->credentials);
        } catch (TransferCancelled) {
            Log::info('Organization import fetch cancelled', ['transfer' => $this->transferId]);

            return;
        } catch (SourceException $e) {
            if (! $e->retryable || $this->attempts() >= $this->tries) {
                // No more attempts: failed() records it (also when run without a queue job)
                if ($this->job) {
                    $this->fail($e);
                } else {
                    $this->failed($e);
                }

                return;
            }
            $transfer->forceFill(['message' => __('org_transfer.source.retrying', ['error' => mb_substr($e->getMessage(), 0, 500)])])->save();

            throw $e;
        }

        $transfers->fetched($transfer->fresh() ?? $transfer);
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('Organization import fetch failed', ['transfer' => $this->transferId, 'error' => $e?->getMessage()]);

        $transfer = OrgTransfer::query()->find($this->transferId);
        if ($transfer && $transfer->status === OrgTransfer::STATUS_FETCHING) {
            $transfer->forceFill([
                'status' => OrgTransfer::STATUS_FAILED,
                'phase' => 'fetch',
                'message' => mb_substr((string) $e?->getMessage(), 0, 1000),
                'finished_at' => now(),
            ])->save();
            OrgTransferOwner::audit($transfer, 'org.import.fetch_failed', AuditEvent::RESULT_FAILURE, [
                'source' => $transfer->import_source['type'] ?? null,
                'received_bytes' => (int) $transfer->uploaded_bytes,
                'error' => mb_substr((string) $e?->getMessage(), 0, 300),
            ]);
        }

        $this->broadcastFailure('org_transfer.failed', ['transfer_id' => $this->transferId, 'kind' => OrgTransfer::KIND_IMPORT, 'status' => OrgTransfer::STATUS_FAILED],
            $e ?? new \RuntimeException('fetch failed'), __('org_transfer.notify.import_fetch_failed'));
    }
}
