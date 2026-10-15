<?php

namespace App\Services\Bindings\Ops;

/**
 * Outcome of an OpsTask: the pod phase and its log. Scripts report values on marker lines
 * ("RUNR_OK", "USAGE <id> <bytes>", "RUNR_RESULT <json>").
 */
final class OpsResult
{
    public function __construct(
        public bool $ok,
        public string $output,
        public string $phase = 'Succeeded',
    ) {}

    public function hasMarker(string $marker): bool
    {
        foreach (preg_split('/\r?\n/', $this->output) as $line) {
            if (trim($line) === $marker || str_starts_with(trim($line), $marker.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rest of every line that starts with "$marker ".
     *
     * @return list<string>
     */
    public function marked(string $marker): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $this->output) as $line) {
            $line = trim($line);
            if (str_starts_with($line, $marker.' ')) {
                $out[] = substr($line, strlen($marker) + 1);
            }
        }

        return $out;
    }

    /** JSON after the first "$marker " line, null when absent or invalid. */
    public function json(string $marker): mixed
    {
        $first = $this->marked($marker)[0] ?? null;

        return $first === null ? null : json_decode($first, true);
    }

    /** Last lines of the log, for error messages. */
    public function tail(int $chars = 400): string
    {
        return trim(mb_substr(trim($this->output), -$chars));
    }
}
