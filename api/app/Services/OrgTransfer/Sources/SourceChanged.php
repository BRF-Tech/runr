<?php

namespace App\Services\OrgTransfer\Sources;

/** The object an import reads was replaced while it was read: the bytes held so far are dropped. */
class SourceChanged extends SourceException
{
    public function __construct(string $message)
    {
        parent::__construct($message, true);
    }
}
