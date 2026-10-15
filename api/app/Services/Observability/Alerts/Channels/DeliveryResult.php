<?php

namespace App\Services\Observability\Alerts\Channels;

use Illuminate\Http\Client\Response;

final class DeliveryResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $code = null,
        public readonly ?string $error = null,
        /** false: retrying cannot help (4xx, invalid configuration) */
        public readonly bool $retryable = true,
    ) {}

    public static function fromResponse(Response $response): self
    {
        if ($response->successful()) {
            return new self(true, $response->status());
        }

        $status = $response->status();

        return new self(false, $status, mb_substr('HTTP '.$status.': '.trim($response->body()), 0, 500), $status >= 500 || $status === 429);
    }

    public static function failed(string $error, bool $retryable = true): self
    {
        return new self(false, null, mb_substr($error, 0, 500), $retryable);
    }
}
