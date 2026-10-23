<?php

namespace App\Services\OrgTransfer\Destinations;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Writes a package to an S3-compatible bucket the user names for one export
 * (docs/org-export.md#destinations): Signature Version 4, a multipart upload in parts of
 * PART_BYTES (one PUT for a smaller package), aborted when a part fails. The credentials are the
 * job's only — never stored, never logged. Its signed send() also serves the ranged reads of an
 * import from a bucket (S3Reader).
 */
class S3Uploader
{
    public const PART_BYTES = 64 * 1024 * 1024;

    /**
     * @param  array{endpoint: string, bucket: string, region?: ?string, access_key_id: string, secret_access_key: string, path_style?: bool}  $target
     * @param  callable(int $done, int $total): void|null  $progress
     *
     * @throws DestinationException
     */
    public function upload(string $file, array $target, string $key, ?callable $progress = null): void
    {
        $size = (int) filesize($file);
        $in = fopen($file, 'rb');
        if ($in === false) {
            throw new DestinationException("Cannot read {$file}.");
        }

        try {
            if ($size <= self::PART_BYTES) {
                $body = (string) stream_get_contents($in);
                $this->expect($this->send($target, 'PUT', $key, [], $body), 'PUT');
                if ($progress) {
                    $progress($size, $size);
                }

                return;
            }

            $create = $this->expect($this->send($target, 'POST', $key, ['uploads' => ''], ''), 'CreateMultipartUpload');
            $uploadId = self::xmlValue($create->body(), 'UploadId') ?? throw new DestinationException(__('org_transfer.destination.s3_failed', ['step' => 'CreateMultipartUpload', 'status' => $create->status()]));

            $parts = [];
            try {
                $number = 1;
                $done = 0;
                while (! feof($in)) {
                    $body = (string) fread($in, self::PART_BYTES);
                    if ($body === '') {
                        break;
                    }
                    $response = $this->expect($this->send($target, 'PUT', $key, ['partNumber' => (string) $number, 'uploadId' => $uploadId], $body), 'UploadPart');
                    $parts[$number] = trim((string) $response->header('ETag'));
                    $done += strlen($body);
                    if ($progress) {
                        $progress($done, $size);
                    }
                    $number++;
                }

                $xml = '<CompleteMultipartUpload>';
                foreach ($parts as $n => $etag) {
                    $xml .= '<Part><PartNumber>'.$n.'</PartNumber><ETag>'.htmlspecialchars($etag, ENT_XML1).'</ETag></Part>';
                }
                $xml .= '</CompleteMultipartUpload>';
                $complete = $this->expect($this->send($target, 'POST', $key, ['uploadId' => $uploadId], $xml, 'application/xml'), 'CompleteMultipartUpload');
                if (str_contains($complete->body(), '<Error>')) {
                    throw new DestinationException(__('org_transfer.destination.s3_failed', ['step' => 'CompleteMultipartUpload', 'status' => $complete->status()]));
                }
            } catch (\Throwable $e) {
                try {
                    $this->send($target, 'DELETE', $key, ['uploadId' => $uploadId], '');
                } catch (\Throwable) {
                }

                throw $e;
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * One signed request (Signature Version 4). $headers are sent unsigned next to the signed
     * ones — Range and If-Match of a ranged read (S3Reader, an import from a bucket).
     *
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     */
    public function send(array $target, string $method, string $key, array $query, string $body, string $contentType = 'application/octet-stream', array $headers = []): Response
    {
        $extra = $headers;
        $endpoint = rtrim((string) $target['endpoint'], '/');
        $parts = parse_url($endpoint);
        $scheme = $parts['scheme'] ?? 'https';
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $basePath = rtrim((string) ($parts['path'] ?? ''), '/');
        $bucket = (string) $target['bucket'];
        $pathStyle = (bool) ($target['path_style'] ?? true);

        $objectPath = '/'.implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
        if ($pathStyle) {
            $hostHeader = $host.$port;
            $path = $basePath.'/'.rawurlencode($bucket).$objectPath;
        } else {
            $hostHeader = $bucket.'.'.$host.$port;
            $path = $basePath.$objectPath;
        }

        ksort($query);
        $canonicalQuery = implode('&', array_map(fn ($k, $v) => rawurlencode((string) $k).'='.rawurlencode((string) $v), array_keys($query), $query));

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $region = (string) (($target['region'] ?? null) ?: 'us-east-1');
        $payloadHash = hash('sha256', $body);

        $headers = ['host' => $hostHeader, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];
        if ($body !== '' || in_array($method, ['PUT', 'POST'], true)) {
            $headers['content-type'] = $contentType;
        }
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.trim($value)."\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [$method, $path, $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash]);
        $scope = "{$date}/{$region}/s3/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $signingKey = hash_hmac('sha256', $date, 'AWS4'.$target['secret_access_key'], true);
        $signingKey = hash_hmac('sha256', $region, $signingKey, true);
        $signingKey = hash_hmac('sha256', 's3', $signingKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $signingKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$target['access_key_id']}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
        unset($headers['host']);

        $url = "{$scheme}://{$hostHeader}{$path}".($canonicalQuery !== '' ? '?'.$canonicalQuery : '');
        $request = Http::withHeaders($headers + $extra)->timeout(600)->connectTimeout(15);
        if ($body !== '' || in_array($method, ['PUT', 'POST'], true)) {
            $request = $request->withBody($body, $contentType);
        }

        return $request->send($method, $url);
    }

    private function expect(Response $response, string $step): Response
    {
        if (! $response->successful()) {
            $code = self::xmlValue($response->body(), 'Code');
            throw new DestinationException(__('org_transfer.destination.s3_failed', ['step' => $step, 'status' => $response->status().($code ? " {$code}" : '')]));
        }

        return $response;
    }

    private static function xmlValue(string $xml, string $element): ?string
    {
        return preg_match('#<'.$element.'>([^<]*)</'.$element.'>#', $xml, $m) ? html_entity_decode($m[1], ENT_XML1) : null;
    }
}
