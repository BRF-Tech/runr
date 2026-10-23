<?php

namespace App\Services\OrgTransfer;

use App\Jobs\OrgTransfer\ApplyImportJob;
use App\Jobs\OrgTransfer\ExportOrganizationJob;
use App\Jobs\OrgTransfer\FetchImportPackageJob;
use App\Jobs\OrgTransfer\VerifyImportPackageJob;
use App\Models\AuditEvent;
use App\Models\Binding;
use App\Models\OrgTransfer;
use App\Models\Project;
use App\Models\User;
use App\Services\OrgTransfer\Package\PackageException;
use Illuminate\Support\Collection;

/**
 * The organization's exports and imports as the API offers them (docs/org-export.md): owner
 * scoping, the limit on running jobs, the export options, the chunked upload of a package (the
 * protocol of image uploads: offset, contiguous bytes, complete) or its fetch from an object
 * binding / S3 bucket (resumable), plan and apply, cancel and the hourly expiry.
 */
class OrgTransferService
{
    public function __construct(
        private OrgImporter $importer,
        private OrgExporter $exporter,
        private OrgTransferProgress $progress,
    ) {}

    /** @return Collection<int, OrgTransfer> */
    public function list(int $limit = 20): Collection
    {
        return OrgTransfer::query()->where('owner_key', OrgTransferOwner::currentKey())->latest()->limit($limit)->get();
    }

    /** The transfer of the current organization, null when it is another's. */
    public function find(string $id): ?OrgTransfer
    {
        return OrgTransfer::query()->where('owner_key', OrgTransferOwner::currentKey())->find($id);
    }

    /**
     * Normalized export options: projects (ids of this organization, null = all), members,
     * images, binding data ("all" or ids), secrets.
     *
     * @return array<string, mixed>
     */
    public function exportOptions(array $input): array
    {
        $projects = null;
        if (is_array($input['projects'] ?? null)) {
            $projects = Project::query()->whereIn('id', array_map('strval', $input['projects']))->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        }

        $data = $input['binding_data'] ?? [];
        if ($data !== 'all') {
            $data = is_array($data)
                ? Binding::query()->whereIn('id', array_map('strval', $data))->pluck('id')->map(fn ($id) => (string) $id)->values()->all()
                : [];
        }

        return [
            'projects' => $projects,
            'include_members' => (bool) ($input['include_members'] ?? false),
            'include_images' => (bool) ($input['include_images'] ?? false),
            'binding_data' => $data,
            'include_secrets' => (bool) ($input['include_secrets'] ?? false),
            'include_history' => (bool) ($input['include_history'] ?? false),
        ];
    }

    /**
     * The destination as stored (no credentials) and the credentials for the job.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>|null}
     */
    public function destination(array $input): array
    {
        $type = $input['type'] ?? 'download';

        return match ($type) {
            'binding' => [[
                'type' => 'binding',
                'binding_id' => (string) $input['binding_id'],
                'binding_name' => Binding::query()->whereKey($input['binding_id'])->value('name'),
                'prefix' => $input['prefix'] ?? 'runr-exports',
            ], null],
            's3' => [[
                'type' => 's3',
                'endpoint' => rtrim((string) $input['endpoint'], '/'),
                'bucket' => (string) $input['bucket'],
                'region' => $input['region'] ?? null,
                'path_style' => (bool) ($input['path_style'] ?? true),
                'prefix' => $input['prefix'] ?? 'runr-exports',
            ], ['access_key_id' => (string) $input['access_key_id'], 'secret_access_key' => (string) $input['secret_access_key']]],
            default => [['type' => 'download'], null],
        };
    }

    /**
     * Queue an export.
     *
     * @param  array<string, mixed>  $options  exportOptions()
     * @param  array<string, mixed>  $destination
     * @param  array<string, string>|null  $credentials
     *
     * @throws TransferLimitReached
     */
    public function startExport(array $options, array $destination, ?array $credentials, ?string $passphrase, User $user): OrgTransfer
    {
        $this->assertCapacity(OrgTransfer::KIND_EXPORT);

        $transfer = OrgTransfer::query()->create([
            'owner_key' => OrgTransferOwner::currentKey(),
            'tenant_id' => OrgTransferOwner::tenantId(),
            'kind' => OrgTransfer::KIND_EXPORT,
            'status' => OrgTransfer::STATUS_QUEUED,
            'progress' => 0,
            'options' => $options,
            'estimate' => $this->exporter->estimate($options),
            'destination' => $destination,
            'created_by' => (string) $user->getKey(),
            'created_by_name' => $user->name,
        ]);

        ExportOrganizationJob::dispatch($transfer->id, $passphrase, $credentials, (string) $user->getKey());

        return $transfer;
    }

    /** @return array<string, mixed> */
    public function estimate(array $options): array
    {
        return $this->exporter->estimate($options);
    }

    /**
     * The source of an import RunR fetches, as stored (no credentials), and the credentials for
     * the job — the counterpart of destination().
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>|null}
     */
    public function source(array $input): array
    {
        $key = ltrim((string) ($input['key'] ?? ''), '/');

        return match ($input['type'] ?? null) {
            'binding' => [[
                'type' => 'binding',
                'binding_id' => (string) $input['binding_id'],
                'binding_name' => Binding::query()->whereKey($input['binding_id'])->value('name'),
                'key' => $key,
            ], null],
            default => [[
                'type' => 's3',
                'endpoint' => rtrim((string) $input['endpoint'], '/'),
                'bucket' => (string) $input['bucket'],
                'region' => $input['region'] ?? null,
                'path_style' => (bool) ($input['path_style'] ?? true),
                'key' => $key,
            ], ['access_key_id' => (string) $input['access_key_id'], 'secret_access_key' => (string) $input['secret_access_key']]],
        };
    }

    /**
     * An import whose package RunR reads from an object binding or an S3 bucket: queued, then
     * verified and planned like an upload.
     *
     * @param  array<string, mixed>  $source  source()
     * @param  array<string, string>|null  $credentials
     *
     * @throws TransferLimitReached
     */
    public function startFetch(array $source, ?array $credentials, ?string $sha256, User $user): OrgTransfer
    {
        $this->assertCapacity(OrgTransfer::KIND_IMPORT);
        $filename = mb_substr(basename(str_replace('\\', '/', (string) ($source['key'] ?? ''))) ?: 'package.tar.zst', 0, 255);

        $transfer = OrgTransfer::query()->create([
            'owner_key' => OrgTransferOwner::currentKey(),
            'tenant_id' => OrgTransferOwner::tenantId(),
            'kind' => OrgTransfer::KIND_IMPORT,
            'status' => OrgTransfer::STATUS_FETCHING,
            'phase' => 'fetch',
            'progress' => 0,
            'options' => ['filename' => $filename],
            'package_filename' => $filename,
            'import_source' => $source,
            'uploaded_bytes' => 0,
            'upload_sha256' => $sha256 !== null && $sha256 !== '' ? strtolower($sha256) : null,
            'created_by' => (string) $user->getKey(),
            'created_by_name' => $user->name,
            'started_at' => now(),
            'expires_at' => now()->addHours((int) config('org_transfer.upload_ttl_hours', 48)),
        ]);

        OrgTransferPaths::ensure(OrgTransferPaths::dir($transfer));
        touch(OrgTransferPaths::package($transfer));
        FetchImportPackageJob::dispatch($transfer->id, $credentials, (string) $user->getKey());

        return $transfer;
    }

    /**
     * Resume the fetch of an import that failed while reading its package (or whose fetch stopped
     * without a word for fetch_stall_minutes): it continues after the bytes it holds.
     *
     * @param  array<string, string>|null  $credentials  an S3 source's, given again
     *
     * @throws TransferRequestException|TransferLimitReached
     */
    public function resumeFetch(OrgTransfer $transfer, ?array $credentials, User $user): OrgTransfer
    {
        $stalled = $transfer->status === OrgTransfer::STATUS_FETCHING
            && $transfer->updated_at !== null && $transfer->updated_at->lt(now()->subMinutes((int) config('org_transfer.fetch_stall_minutes', 30)));
        $failed = $transfer->status === OrgTransfer::STATUS_FAILED && $transfer->phase === 'fetch';
        if (! $transfer->isFetched() || (! $failed && ! $stalled)) {
            throw new TransferRequestException(__('org_transfer.source.not_resumable', ['status' => $transfer->status]), 409, 'not_resumable');
        }
        if (($transfer->import_source['type'] ?? null) === 's3' && (empty($credentials['access_key_id']) || empty($credentials['secret_access_key']))) {
            throw new TransferRequestException(__('org_transfer.source.s3_credentials'), 422, 's3_credentials');
        }
        $this->assertCapacity(OrgTransfer::KIND_IMPORT, $transfer->id);

        OrgTransferPaths::ensure(OrgTransferPaths::dir($transfer));
        $this->progress->update($transfer, [
            'status' => OrgTransfer::STATUS_FETCHING,
            'phase' => 'fetch',
            'message' => null,
            'finished_at' => null,
            'expires_at' => now()->addHours((int) config('org_transfer.upload_ttl_hours', 48)),
        ]);
        FetchImportPackageJob::dispatch($transfer->id, $credentials, (string) $user->getKey());

        return $transfer->fresh() ?? $transfer;
    }

    /** The fetch read every byte (and checked them): verify and plan as for a completed upload. */
    public function fetched(OrgTransfer $transfer): void
    {
        $this->progress->update($transfer, [
            'status' => OrgTransfer::STATUS_VERIFYING,
            'phase' => 'verify',
            'progress' => 0,
            'uploaded_bytes' => (int) $transfer->upload_size,
            'message' => __('org_transfer.progress.verifying'),
        ]);
        OrgTransferOwner::audit($transfer, 'org.import.fetched', AuditEvent::RESULT_SUCCESS, [
            'source' => $transfer->import_source['type'] ?? null,
            'size' => (int) $transfer->upload_size,
            'sha256' => (string) hash_file('sha256', OrgTransferPaths::package($transfer)),
        ]);
        VerifyImportPackageJob::dispatch($transfer->id, (string) $transfer->created_by);
    }

    /** @throws TransferLimitReached */
    public function startImport(string $filename, int $size, ?string $sha256, User $user): OrgTransfer
    {
        $this->assertCapacity(OrgTransfer::KIND_IMPORT);

        $transfer = OrgTransfer::query()->create([
            'owner_key' => OrgTransferOwner::currentKey(),
            'tenant_id' => OrgTransferOwner::tenantId(),
            'kind' => OrgTransfer::KIND_IMPORT,
            'status' => OrgTransfer::STATUS_UPLOADING,
            'progress' => 0,
            'options' => ['filename' => mb_substr(basename(str_replace('\\', '/', $filename)), 0, 255)],
            'package_filename' => mb_substr(basename(str_replace('\\', '/', $filename)), 0, 255),
            'upload_size' => $size,
            'uploaded_bytes' => 0,
            'chunk_size' => (int) config('org_transfer.chunk_bytes', 8 * 1024 * 1024),
            'upload_sha256' => $sha256 !== null && $sha256 !== '' ? strtolower($sha256) : null,
            'created_by' => (string) $user->getKey(),
            'created_by_name' => $user->name,
            'expires_at' => now()->addHours((int) config('org_transfer.upload_ttl_hours', 48)),
        ]);

        OrgTransferPaths::ensure(OrgTransferPaths::dir($transfer));
        touch(OrgTransferPaths::package($transfer));

        return $transfer;
    }

    /**
     * Write one chunk at $offset; the upload advances over contiguous bytes only, so a client
     * resumes after uploaded_bytes.
     *
     * @return array{uploaded_bytes: int, size_bytes: int}
     *
     * @throws TransferRequestException
     */
    public function chunk(OrgTransfer $transfer, int $offset, string $bytes): array
    {
        $length = strlen($bytes);
        $size = (int) $transfer->upload_size;
        if ($length === 0 || $length > (int) $transfer->chunk_size || $offset < 0 || $offset + $length > $size || $offset > (int) $transfer->uploaded_bytes) {
            throw new TransferRequestException(__('org_transfer.upload.bad_chunk', ['offset' => $offset, 'uploaded' => (int) $transfer->uploaded_bytes, 'size' => $size, 'chunk' => (int) $transfer->chunk_size]), 422, 'bad_chunk', ['uploaded_bytes' => (int) $transfer->uploaded_bytes]);
        }

        $path = OrgTransferPaths::package($transfer);
        $out = @fopen($path, 'c+b');
        if ($out === false) {
            throw new TransferRequestException(__('org_transfer.upload.write_failed'), 500, 'write_failed');
        }
        try {
            if (fseek($out, $offset) !== 0 || fwrite($out, $bytes) !== $length) {
                throw new TransferRequestException(__('org_transfer.upload.write_failed'), 500, 'write_failed');
            }
            fflush($out);
        } finally {
            fclose($out);
        }

        OrgTransfer::query()->whereKey($transfer->id)->where('uploaded_bytes', '<', $offset + $length)->update(['uploaded_bytes' => $offset + $length, 'updated_at' => now()]);
        $uploaded = (int) OrgTransfer::query()->whereKey($transfer->id)->value('uploaded_bytes');

        return ['uploaded_bytes' => $uploaded, 'size_bytes' => $size];
    }

    /**
     * All bytes are there: check the declared SHA-256, then verify and plan in the background.
     *
     * @throws TransferRequestException
     */
    public function complete(OrgTransfer $transfer): OrgTransfer
    {
        if ((int) $transfer->uploaded_bytes < (int) $transfer->upload_size) {
            throw new TransferRequestException(__('org_transfer.upload.incomplete', ['received' => (int) $transfer->uploaded_bytes, 'size' => (int) $transfer->upload_size]), 422, 'upload_incomplete', ['uploaded_bytes' => (int) $transfer->uploaded_bytes]);
        }

        $path = OrgTransferPaths::package($transfer);
        clearstatcache(true, $path);
        if ((int) @filesize($path) !== (int) $transfer->upload_size) {
            $transfer->update(['uploaded_bytes' => 0]);
            @unlink($path);
            touch($path);

            throw new TransferRequestException(__('org_transfer.upload.size_mismatch'), 422, 'upload_incomplete', ['uploaded_bytes' => 0]);
        }
        if ($transfer->upload_sha256 !== null && ! hash_equals($transfer->upload_sha256, (string) hash_file('sha256', $path))) {
            $transfer->update(['uploaded_bytes' => 0]);
            @unlink($path);
            touch($path);

            throw new TransferRequestException(__('org_transfer.upload.checksum_mismatch'), 422, 'checksum_mismatch', ['uploaded_bytes' => 0]);
        }

        $this->progress->update($transfer, ['status' => OrgTransfer::STATUS_VERIFYING, 'phase' => 'verify', 'progress' => 0, 'message' => __('org_transfer.progress.verifying')]);
        VerifyImportPackageJob::dispatch($transfer->id, (string) $transfer->created_by);

        return $transfer->fresh() ?? $transfer;
    }

    /**
     * A fresh plan for $choices (synchronous: the package is unpacked already).
     *
     * @throws TransferRequestException
     */
    public function plan(OrgTransfer $transfer, ImportChoices $choices, User $user): OrgTransfer
    {
        $this->assertPlannable($transfer);

        try {
            $plan = $this->importer->plan($transfer, $choices, $user);
        } catch (PackageException $e) {
            throw new TransferRequestException($e->getMessage(), 422, $e->errorCode);
        }

        $transfer->update(['plan' => $plan, 'options' => $choices->toStored() + ['filename' => $transfer->options['filename'] ?? null]]);

        return $transfer->fresh() ?? $transfer;
    }

    /**
     * Apply the import with $choices; refused while the plan has blocking issues. A failed apply
     * is resumed by applying again.
     *
     * @throws TransferRequestException|TransferLimitReached
     */
    public function apply(OrgTransfer $transfer, ImportChoices $choices, User $user): OrgTransfer
    {
        $transfer = $this->plan($transfer, $choices, $user);
        if (! ($transfer->plan['can_apply'] ?? false)) {
            throw new TransferRequestException(__('org_transfer.plan.blocked', ['count' => (int) ($transfer->plan['blocking'] ?? 0)]), 422, 'plan_blocked', ['data' => $transfer->toApi()]);
        }
        $this->assertCapacity(OrgTransfer::KIND_IMPORT, $transfer->id);

        $this->progress->update($transfer, ['status' => OrgTransfer::STATUS_APPLYING, 'phase' => 'apply', 'progress' => 0, 'message' => null, 'finished_at' => null]);
        ApplyImportJob::dispatch($transfer->id, $choices->passphrase, (string) $user->getKey());

        return $transfer->fresh() ?? $transfer;
    }

    /** Cancel a transfer that runs (its job stops between two steps) and remove its files. */
    public function cancel(OrgTransfer $transfer): void
    {
        if (! $transfer->isFinished()) {
            $this->progress->update($transfer, ['status' => OrgTransfer::STATUS_CANCELLED, 'message' => __('org_transfer.cancelled'), 'finished_at' => now()]);
        } else {
            $transfer->update(['expires_at' => now()]);
        }
        OrgTransferPaths::remove($transfer);
    }

    /**
     * Hourly: uploads not completed in time expire, packages past their retention go (the row
     * stays, without a download), directories of transfers whose row is gone are removed.
     */
    public function expire(): int
    {
        // A fetch whose worker went away (a restart) without a word: failed, resumable
        $stalled = OrgTransfer::query()->where('status', OrgTransfer::STATUS_FETCHING)
            ->where('updated_at', '<', now()->subMinutes((int) config('org_transfer.fetch_stall_minutes', 30)))->get();
        foreach ($stalled as $transfer) {
            $transfer->update(['status' => OrgTransfer::STATUS_FAILED, 'phase' => 'fetch', 'message' => __('org_transfer.source.stalled'), 'finished_at' => now()]);
        }

        $n = 0;
        $expired = OrgTransfer::query()->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->whereNotIn('status', [OrgTransfer::STATUS_EXPIRED])->get();
        foreach ($expired as $transfer) {
            if ($transfer->isRunning()) {
                continue;
            }
            OrgTransferPaths::remove($transfer);
            if (! $transfer->isFinished()) {
                $transfer->update(['status' => OrgTransfer::STATUS_EXPIRED, 'message' => __('org_transfer.expired'), 'finished_at' => now()]);
            }
            $n++;
        }

        foreach (OrgTransferPaths::orphans() as $dir) {
            OrgTransferPaths::removeTree($dir);
        }

        return $n;
    }

    /** @throws TransferRequestException */
    private function assertPlannable(OrgTransfer $transfer): void
    {
        if ($transfer->isExport() || ! in_array($transfer->status, [OrgTransfer::STATUS_PLANNED, OrgTransfer::STATUS_FAILED], true) || $transfer->plan === null) {
            throw new TransferRequestException(__('org_transfer.not_plannable', ['status' => $transfer->status]), 409, 'not_plannable');
        }
    }

    /**
     * One running job of a kind per organization (config max_running_per_organization) and a
     * few per installation (max_running).
     *
     * @throws TransferLimitReached
     */
    private function assertCapacity(string $kind, ?string $except = null): void
    {
        $running = fn () => OrgTransfer::query()->whereIn('status', OrgTransfer::RUNNING)->when($except, fn ($q) => $q->whereKeyNot($except));

        $own = $running()->where('owner_key', OrgTransferOwner::currentKey())->where('kind', $kind)->count();
        if ($own >= (int) config('org_transfer.max_running_per_organization', 1)) {
            throw new TransferLimitReached(__('org_transfer.limit.organization', ['kind' => $kind]));
        }
        if ($running()->count() >= (int) config('org_transfer.max_running', 4)) {
            throw new TransferLimitReached(__('org_transfer.limit.installation'));
        }
    }
}
