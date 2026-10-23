<?php

namespace App\Services\OrgTransfer\Sources;

use App\Services\OrgTransfer\Destinations\S3Uploader;
use Illuminate\Http\Client\Response;

/**
 * Reads a package from an S3-compatible bucket the user names for one import
 * (docs/org-export.md#import): HeadObject for its size and ETag, then GetObject in byte ranges
 * with If-Match on that ETag — an object replaced while the fetch runs answers 412 instead of
 * mixing two packages. Signature Version 4 through S3Uploader::send(); the credentials are the
 * fetch job's only, never stored or logged.
 */
class S3Reader
{
    public function __construct(private S3Uploader $s3) {}

    /**
     * @param  array{endpoint: string, bucket: string, region?: ?string, access_key_id: string, secret_access_key: string, path_style?: bool}  $target
     * @return array{size: int, etag: ?string}
     *
     * @throws SourceException
     */
    public function head(array $target, string $key): array
    {
        $response = $this->call(fn () => $this->s3->send($target, 'HEAD', $key, [], ''), 'HeadObject');
        if ($response->status() === 404) {
            throw new SourceException(__('org_transfer.source.not_found', ['key' => $key]));
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new SourceException(__('org_transfer.source.s3_denied', ['key' => $key, 'status' => $response->status()]));
        }
        if (! $response->successful()) {
            throw new SourceException(__('org_transfer.source.s3_failed', ['step' => 'HeadObject', 'status' => $response->status()]), $response->serverError());
        }
        $etag = trim((string) $response->header('ETag'));

        return ['size' => (int) $response->header('Content-Length'), 'etag' => $etag !== '' ? $etag : null];
    }

    /**
     * Bytes $offset … $end (inclusive) of the object.
     *
     * @throws SourceChanged the object is not the one with $etag any more
     * @throws SourceException
     */
    public function range(array $target, string $key, int $offset, int $end, ?string $etag): string
    {
        $headers = ['Range' => "bytes={$offset}-{$end}"];
        if ($etag !== null) {
            $headers['If-Match'] = $etag;
        }
        $response = $this->call(fn () => $this->s3->send($target, 'GET', $key, [], '', 'application/octet-stream', $headers), 'GetObject');

        if ($response->status() === 412) {
            throw new SourceChanged(__('org_transfer.source.changed', ['key' => $key]));
        }
        if ($response->status() === 206) {
            return $response->body();
        }
        if ($response->status() === 200) {
            // A server that ignores Range sends the whole object: keep the part asked for
            return substr($response->body(), $offset, $end - $offset + 1);
        }
        if ($response->status() === 404) {
            throw new SourceException(__('org_transfer.source.not_found', ['key' => $key]));
        }

        throw new SourceException(__('org_transfer.source.s3_failed', ['step' => 'GetObject', 'status' => $response->status()]), $response->status() >= 500 || $response->status() === 429);
    }

    /**
     * @param  callable(): Response  $send
     *
     * @throws SourceException a connection error (retryable)
     */
    private function call(callable $send, string $step): Response
    {
        try {
            return $send();
        } catch (\Throwable $e) {
            throw new SourceException(__('org_transfer.source.s3_failed', ['step' => $step, 'status' => mb_substr($e->getMessage(), 0, 200)]), true, $e);
        }
    }
}
