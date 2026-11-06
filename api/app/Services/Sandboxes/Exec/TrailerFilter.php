<?php

namespace App\Services\Sandboxes\Exec;

/**
 * Streams kubectl's stderr without its closing "command terminated with exit code N" line: the
 * last complete line is held back while it looks like that trailer and released when more output
 * follows, so a command's own stderr streams as it comes. When the command's stderr does not end
 * in a newline, kubectl's trailer ends its last line: a line or partial line that ends like the
 * trailer is held back the same way, and only the trailer goes at the end.
 */
final class TrailerFilter
{
    private const TRAILER = 'command terminated with exit code ';

    private string $buffer = '';

    private ?string $held = null;

    /** Feed a chunk; returns the bytes that can be emitted now. */
    public function push(string $chunk): string
    {
        if ($chunk === '') {
            return '';
        }

        $this->buffer .= $chunk;
        $out = '';

        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos + 1);
            $this->buffer = substr($this->buffer, $pos + 1);

            if ($this->held !== null) {
                $out .= $this->held;
                $this->held = null;
            }
            if (self::trailerAt($line) !== null) {
                $this->held = $line;
            } else {
                $out .= $line;
            }
        }

        // A partial line streams, but for an end that may become the trailer
        $keep = self::trailerStart($this->buffer);
        if ($keep < strlen($this->buffer)) {
            if ($this->held !== null) {
                $out .= $this->held;
                $this->held = null;
            }
            $out .= substr($this->buffer, 0, strlen($this->buffer) - $keep);
            $this->buffer = $keep > 0 ? substr($this->buffer, -$keep) : '';
        }

        return $out;
    }

    /** End of output: whatever is left, without the trailer. */
    public function finish(): string
    {
        $held = $this->held;
        $rest = $this->buffer;
        $this->buffer = '';
        $this->held = null;
        if ($rest !== '') {
            // The held line was not the last one
            $at = self::trailerAt($rest);

            return ($held ?? '').($at === null ? $rest : substr($rest, 0, $at));
        }
        if ($held === null) {
            return '';
        }
        $at = self::trailerAt($held);

        return $at === null ? $held : substr($held, 0, $at);
    }

    /** Where a trailer that ends $line starts (null: none). */
    private static function trailerAt(string $line): ?int
    {
        if (! preg_match('/command terminated with exit code \d+\r?\n?$/', $line, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return (int) $m[0][1];
    }

    /** Length of the end of a partial line that may be the start of the trailer. */
    private static function trailerStart(string $partial): int
    {
        for ($n = min(strlen($partial), strlen(self::TRAILER) + 24); $n > 0; $n--) {
            $end = substr($partial, -$n);
            if (self::mayBeTrailer($end) || preg_match('/^command terminated with exit code \d*\r?$/', $end)) {
                return $n;
            }
        }

        return 0;
    }

    private static function mayBeTrailer(string $partial): bool
    {
        $n = min(strlen($partial), strlen(self::TRAILER));

        return strncmp($partial, self::TRAILER, $n) === 0;
    }
}
