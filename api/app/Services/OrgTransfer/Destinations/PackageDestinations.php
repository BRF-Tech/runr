<?php

namespace App\Services\OrgTransfer\Destinations;

use App\Models\Binding;
use App\Services\Bindings\BindingsCluster;
use App\Services\OrgTransfer\Region\BindingDumps;
use App\Services\OrgTransfer\Region\RegionTransfer;

/**
 * Where a finished export's package goes (docs/org-export.md#destinations):
 *
 *  - download  stays on the API's volume until it expires, streamed by GET …/download
 *  - binding   an object binding of the organization: a transfer pod in the binding's region
 *              receives the package and copies it into the bucket with the binding's own key
 *  - s3        any S3-compatible bucket; URL and credentials are given for this one export and
 *              kept nowhere (S3Uploader)
 */
class PackageDestinations
{
    public function __construct(
        private RegionTransfer $transfer,
        private BindingDumps $dumps,
        private S3Uploader $s3,
    ) {}

    /** Object key of the package under the destination's prefix. */
    public static function key(?string $prefix, string $filename): string
    {
        $prefix = trim(preg_replace('#[^A-Za-z0-9._/-]#', '-', (string) $prefix) ?? '', '/');
        $prefix = implode('/', array_filter(explode('/', $prefix), fn ($p) => $p !== '' && $p !== '.' && $p !== '..'));

        return ($prefix !== '' ? $prefix.'/' : '').$filename;
    }

    /**
     * @param  array<string, mixed>  $destination  the stored destination (no credentials)
     * @param  array<string, mixed>|null  $credentials  S3 credentials of this export
     * @param  callable(int $done, int $total): void|null  $progress
     *
     * @throws DestinationException
     */
    public function write(string $package, string $filename, array $destination, ?array $credentials, ?callable $progress = null): string
    {
        $key = self::key($destination['prefix'] ?? null, $filename);

        return match ($destination['type'] ?? 'download') {
            'binding' => $this->toBinding($package, (string) ($destination['binding_id'] ?? ''), $key, $progress),
            's3' => $this->toS3($package, $destination, $credentials, $key, $progress),
            default => $key,
        };
    }

    private function toBinding(string $package, string $bindingId, string $key, ?callable $progress): string
    {
        $binding = Binding::query()->with('region')->find($bindingId);
        if (! $binding || $binding->type !== 'object' || ! $binding->isActive() || ! $binding->region?->kubeconfig) {
            throw new DestinationException(__('org_transfer.destination.binding_unavailable'));
        }

        $file = 'package.tar.zst';
        $handle = null;
        try {
            app(BindingsCluster::class)->ensureDataNamespace($binding->region);
            $handle = $this->transfer->startReceiver($binding->region, BindingsCluster::dataNamespace(), 'export-'.$binding->resource_name,
                $this->dumps->bucketUploadTool($binding, $file, $key), [$file => ['size' => (int) filesize($package), 'sha256' => (string) hash_file('sha256', $package)]]);
            $this->transfer->waitFor($handle, ['receiving', 'ready', 'done'], null, 300);
            $this->transfer->upload($handle, $file, $package, $progress);
            $this->transfer->waitFor($handle, ['done']);
        } catch (\Throwable $e) {
            throw $e instanceof DestinationException ? $e : new DestinationException($e->getMessage(), 0, $e);
        } finally {
            if ($handle) {
                $this->transfer->stop($handle);
            }
        }

        return $key;
    }

    private function toS3(string $package, array $destination, ?array $credentials, string $key, ?callable $progress): string
    {
        if (empty($credentials['access_key_id']) || empty($credentials['secret_access_key'])) {
            throw new DestinationException(__('org_transfer.destination.s3_credentials'));
        }

        $this->s3->upload($package, [
            'endpoint' => (string) $destination['endpoint'],
            'bucket' => (string) $destination['bucket'],
            'region' => $destination['region'] ?? null,
            'path_style' => (bool) ($destination['path_style'] ?? true),
            'access_key_id' => (string) $credentials['access_key_id'],
            'secret_access_key' => (string) $credentials['secret_access_key'],
        ], $key, $progress);

        return $key;
    }
}
