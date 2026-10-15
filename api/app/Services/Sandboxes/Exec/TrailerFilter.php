<?php

namespace App\Services\Sandboxes\Exec;

/**
 * Streams kubectl's stderr without its closing "command terminated with exit code N" line: the
 * last complete line is held back while it looks like that trailer and released when more output
 * follows, so a command's own stderr streams as it comes.
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
            if (self::isTrailer($line)) {
                $this->held = $line;
            } else {
                $out .= $line;
            }
        }

        // A partial line streams unless it may become the trailer
        if ($this->buffer !== '' && ! self::mayBeTrailer($this->buffer)) {
            if ($this->held !== null) {
                $out .= $this->held;
                $this->held = null;
            }
            $out .= $this->buffer;
            $this->buffer = '';
        }

        return $out;
    }

    /** End of output: whatever is left, without the trailer. */
    public function finish(): string
    {
        $rest = $this->buffer;
        $this->buffer = '';
        $this->held = null;

        return self::isTrailer($rest) ? '' : $rest;
    }

    private static function isTrailer(string $line): bool
    {
        return (bool) preg_match('/^command terminated with exit code \d+\r?\n?$/', $line);
    }

    private static function mayBeTrailer(string $partial): bool
    {
        $n = min(strlen($partial), strlen(self::TRAILER));

        return strncmp($partial, self::TRAILER, $n) === 0;
    }
}
