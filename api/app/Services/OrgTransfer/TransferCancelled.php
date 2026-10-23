<?php

namespace App\Services\OrgTransfer;

/** The user cancelled the transfer while its job ran: the job stops between two steps. */
final class TransferCancelled extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The transfer was cancelled.');
    }
}
