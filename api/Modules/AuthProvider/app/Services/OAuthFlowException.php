<?php

namespace Modules\AuthProvider\Services;

use RuntimeException;

/** A sign-in or link step that cannot go on; the message is shown to the user, $status is the HTTP answer. */
class OAuthFlowException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
