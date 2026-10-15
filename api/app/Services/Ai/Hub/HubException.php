<?php

namespace App\Services\Ai\Hub;

/** A Hub request that cannot be answered; the code is the HTTP status the API returns. */
class HubException extends \RuntimeException
{
    public function __construct(string $message, int $status = 502)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode() ?: 502;
    }
}
