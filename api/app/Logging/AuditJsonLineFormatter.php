<?php

namespace App\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * `audit` log channel: the audit event itself as one JSON line (no Monolog envelope), so a log
 * collector can ship it to a SIEM unchanged. AuditLogger passes the event as the record context.
 */
class AuditJsonLineFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        $event = $record->context['audit'] ?? $record->context;

        return json_encode(
            ['log_type' => 'runr.audit'] + (array) $event,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        )."\n";
    }

    public function formatBatch(array $records): string
    {
        return implode('', array_map(fn (LogRecord $r) => $this->format($r), $records));
    }
}
