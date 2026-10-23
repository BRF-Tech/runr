<?php

namespace App\Services\OrgTransfer\Sources;

use App\Models\Binding;
use App\Models\OrgTransfer;
use App\Services\Bindings\BindingsCluster;
use App\Services\OrgTransfer\OrgTransferPaths;
use App\Services\OrgTransfer\OrgTransferProgress;
use App\Services\OrgTransfer\Region\BindingDumps;
use App\Services\OrgTransfer\Region\RegionTransfer;
use App\Services\OrgTransfer\Region\RegionTransferException;
use App\Services\OrgTransfer\TransferCancelled;

/**
 * Where an import reads its package from when it is not uploaded (docs/org-export.md#import) —
 * the same places an export writes to:
 *
 *  - binding  an object binding of the organization: a transfer pod in the binding's region copies
 *             the object out of the bucket with the binding's own key and serves it with its size
 *             and SHA-256; RunR downloads it in ranges through the Kubernetes API server (the region
 *             needs no route to RunR, as for binding data)
 *  - s3       any S3-compatible bucket: HeadObject, then ranged GetObject with If-Match on the
 *             object's ETag (S3Reader); the credentials are the fetch job's only
 *
 * Resumable: the bytes already in the import's package file stay and the read continues after
 * them — a retry of the job, or POST …/fetch after a failure. They are dropped when the object is
 * not the one they came from any more (another size, ETag or SHA-256). Once all bytes are there
 * the file is checked against the SHA-256 the user declared and, for a binding, the one the
 * region computed; then the upload's path takes over (verify → plan).
 */
class PackageSources
{
    /** The package's name in the transfer pod. */
    public const PACKAGE_FILE = 'package.tar.zst';

    /** Seconds between two "still copying" updates while the pod copies the object. */
    private const TICK_SECONDS = 30;

    /** Attempts of one range before the job gives up (the job's own retry resumes after). */
    private const RANGE_ATTEMPTS = 4;

    public function __construct(
        private RegionTransfer $transfer,
        private BindingDumps $dumps,
        private S3Reader $s3,
        private OrgTransferProgress $progress,
    ) {}

    /**
     * Read the import's package into its package file, after the bytes it holds already.
     *
     * @param  array<string, string>|null  $credentials  S3 credentials of this fetch
     *
     * @throws SourceException|TransferCancelled
     */
    public function fetch(OrgTransfer $transfer, ?array $credentials): void
    {
        $source = (array) ($transfer->import_source ?? []);
        $path = OrgTransferPaths::package($transfer);
        OrgTransferPaths::ensure(dirname($path));
        if (! is_file($path)) {
            touch($path);
        }

        $regionSha = match ($source['type'] ?? null) {
            's3' => $this->fromS3($transfer, $source, $credentials, $path),
            'binding' => $this->fromBinding($transfer, $source, $path),
            default => throw new SourceException(__('org_transfer.source.unknown')),
        };

        clearstatcache(true, $path);
        if ((int) filesize($path) !== (int) $transfer->upload_size) {
            self::truncate($path);

            throw new SourceException(__('org_transfer.upload.size_mismatch'), true);
        }
        $sha = (string) hash_file('sha256', $path);
        if ($regionSha !== null && ! hash_equals($regionSha, $sha)) {
            // Damaged on the way: read it again
            self::truncate($path);

            throw new SourceException(__('org_transfer.source.checksum_mismatch'), true);
        }
        if ($transfer->upload_sha256 !== null && ! hash_equals($transfer->upload_sha256, $sha)) {
            self::truncate($path);

            throw new SourceException(__('org_transfer.upload.checksum_mismatch'));
        }
    }

    /** @return null the bucket gives no SHA-256 to compare with (the declared one is checked) */
    private function fromS3(OrgTransfer $transfer, array $source, ?array $credentials, string $path): null
    {
        if (empty($credentials['access_key_id']) || empty($credentials['secret_access_key'])) {
            throw new SourceException(__('org_transfer.source.s3_credentials'));
        }
        $target = [
            'endpoint' => (string) ($source['endpoint'] ?? ''),
            'bucket' => (string) ($source['bucket'] ?? ''),
            'region' => $source['region'] ?? null,
            'path_style' => (bool) ($source['path_style'] ?? true),
            'access_key_id' => (string) $credentials['access_key_id'],
            'secret_access_key' => (string) $credentials['secret_access_key'],
        ];
        $key = (string) ($source['key'] ?? '');

        for ($restarts = 0; ; $restarts++) {
            $head = $this->s3->head($target, $key);
            $this->assertSize($head['size']);
            $held = self::held($path);
            if ($held > 0 && (($source['etag'] ?? null) !== $head['etag'] || (int) ($source['size'] ?? -1) !== $head['size'])) {
                // Another object than the one the bytes came from
                self::truncate($path);
                $held = 0;
            }
            $source = array_merge($source, ['size' => $head['size'], 'etag' => $head['etag']]);
            $this->record($transfer, $source, $head['size'], $held);

            try {
                $this->readRanges($transfer, $path, $held, $head['size'],
                    fn (int $from, int $to) => $this->s3->range($target, $key, $from, $to, $head['etag']));

                return null;
            } catch (SourceChanged $e) {
                if ($restarts >= 2) {
                    throw $e;
                }
                self::truncate($path);
            }
        }
    }

    /** @return string the SHA-256 the region computed */
    private function fromBinding(OrgTransfer $transfer, array $source, string $path): string
    {
        $binding = Binding::query()->with('region')->find((string) ($source['binding_id'] ?? ''));
        if (! $binding || $binding->type !== 'object' || ! $binding->isActive() || ! $binding->region?->kubeconfig) {
            throw new SourceException(__('org_transfer.source.binding_unavailable'));
        }

        $handle = null;
        try {
            app(BindingsCluster::class)->ensureDataNamespace($binding->region);
            $handle = $this->transfer->startSender($binding->region, BindingsCluster::dataNamespace(), 'import-'.$binding->resource_name,
                $this->dumps->bucketDownloadTool($binding, (string) ($source['key'] ?? ''), self::PACKAGE_FILE));

            $last = 0;
            try {
                $status = $this->transfer->waitFor($handle, ['done'], function () use ($transfer, $binding, &$last) {
                    if (time() - $last >= self::TICK_SECONDS) {
                        $last = time();
                        $this->assertNotCancelled($transfer);
                        $this->progress->update($transfer, ['phase' => 'fetch', 'message' => __('org_transfer.progress.fetch_copy', ['name' => $binding->name])]);
                    }
                });
            } catch (RegionTransferException $e) {
                // The tool could not read the object (missing, no access) or the pod did not run
                throw new SourceException($e->getMessage(), false, $e);
            }

            $file = collect((array) ($status['files'] ?? []))->firstWhere('name', self::PACKAGE_FILE);
            if (! is_array($file)) {
                throw new SourceException(__('org_transfer.region.file_missing', ['name' => self::PACKAGE_FILE]), true);
            }
            $size = (int) ($file['size'] ?? 0);
            $sha = strtolower((string) ($file['sha256'] ?? ''));
            $this->assertSize($size);

            $held = self::held($path);
            if ($held > 0 && (($source['sha256'] ?? null) !== $sha || (int) ($source['size'] ?? -1) !== $size)) {
                self::truncate($path);
                $held = 0;
            }
            $this->record($transfer, array_merge($source, ['size' => $size, 'sha256' => $sha]), $size, $held);

            try {
                $this->transfer->download($handle, self::PACKAGE_FILE, $size, $path, function (int $done, int $total) use ($transfer) {
                    $this->assertNotCancelled($transfer);
                    $this->recordBytes($transfer, $done, $total);
                });
            } catch (RegionTransferException $e) {
                throw new SourceException($e->getMessage(), true, $e);
            }

            return $sha;
        } finally {
            if ($handle) {
                $this->transfer->stop($handle);
            }
        }
    }

    /**
     * Read $size bytes in ranges into $path, from $offset on.
     *
     * @param  callable(int $from, int $to): string  $read
     */
    private function readRanges(OrgTransfer $transfer, string $path, int $offset, int $size, callable $read): void
    {
        $chunk = max(1024 * 1024, (int) config('org_transfer.fetch_chunk_bytes', 16 * 1024 * 1024));
        $out = fopen($path, 'c+b');
        if ($out === false) {
            throw new SourceException(__('org_transfer.upload.write_failed'));
        }

        try {
            if (fseek($out, $offset) !== 0) {
                throw new SourceException(__('org_transfer.upload.write_failed'));
            }
            while ($offset < $size) {
                $this->assertNotCancelled($transfer);
                $end = min($size, $offset + $chunk) - 1;
                $bytes = $this->attempt(fn () => $read($offset, $end));
                if ($bytes === '') {
                    throw new SourceException(__('org_transfer.source.s3_failed', ['step' => 'GetObject', 'status' => 'empty']), true);
                }
                if (fwrite($out, $bytes) !== strlen($bytes)) {
                    throw new SourceException(__('org_transfer.upload.write_failed'));
                }
                fflush($out);
                $offset += strlen($bytes);
                $this->recordBytes($transfer, $offset, $size);
            }
            ftruncate($out, $size);
        } finally {
            fclose($out);
        }
    }

    /**
     * A range, tried again a few times when the failure is retryable (the object changing is not
     * tried again here: the caller starts over).
     *
     * @param  callable(): string  $read
     */
    private function attempt(callable $read): string
    {
        for ($i = 1; ; $i++) {
            try {
                return $read();
            } catch (SourceChanged $e) {
                throw $e;
            } catch (SourceException $e) {
                if (! $e->retryable || $i >= self::RANGE_ATTEMPTS) {
                    throw $e;
                }
                $this->pause(2 * $i);
            }
        }
    }

    /** The size of the object is known: the import takes it, the bytes held so far are its. */
    private function record(OrgTransfer $transfer, array $source, int $size, int $held): void
    {
        $this->progress->update($transfer, [
            'import_source' => $source,
            'upload_size' => $size,
            'uploaded_bytes' => $held,
            'phase' => 'fetch',
            'progress' => $size > 0 ? (int) floor(99 * $held / $size) : 0,
            'message' => __('org_transfer.progress.fetch', ['key' => (string) ($source['key'] ?? '')]),
        ]);
    }

    private function recordBytes(OrgTransfer $transfer, int $done, int $total): void
    {
        $this->progress->update($transfer, [
            'uploaded_bytes' => $done,
            'phase' => 'fetch',
            'progress' => $total > 0 ? (int) floor(99 * min($done, $total) / $total) : 0,
        ]);
    }

    /** @throws SourceException */
    private function assertSize(int $size): void
    {
        $max = (int) config('org_transfer.max_package_gb', 50) * 1024 * 1024 * 1024;
        if ($size <= 0) {
            throw new SourceException(__('org_transfer.source.empty'));
        }
        if ($size > $max) {
            throw new SourceException(__('org_transfer.source.too_large', ['size' => $size, 'max' => (int) config('org_transfer.max_package_gb', 50)]));
        }
    }

    /** @throws TransferCancelled */
    private function assertNotCancelled(OrgTransfer $transfer): void
    {
        $status = OrgTransfer::query()->whereKey($transfer->id)->value('status');
        if ($status === null || $status === OrgTransfer::STATUS_CANCELLED) {
            throw new TransferCancelled;
        }
    }

    private static function held(string $path): int
    {
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : 0;
    }

    private static function truncate(string $path): void
    {
        $out = @fopen($path, 'wb');
        if ($out !== false) {
            fclose($out);
        }
    }

    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }
}
