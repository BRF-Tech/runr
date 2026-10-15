<?php

namespace App\Workflows\Transport;

use RuntimeException;

/** The function could not be reached or answered with a non-2xx status. */
class TransportException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly ?string $body = null)
    {
        parent::__construct($message);
    }

    /** Error object stored on the run (body truncated: it may be an HTML error page). */
    public function toError(): array
    {
        $message = $this->getMessage();
        if ($this->body !== null && $this->body !== '') {
            $message .= ': '.mb_substr(trim(strip_tags($this->body)), 0, 500);
        }

        return [
            'name' => $this->status === 401 ? 'SignatureRejected' : 'FunctionUnavailable',
            'message' => $message,
        ];
    }
}
