<?php

namespace App\Services\SupplyChain;

/**
 * What a supply-chain Job printed (runtime/supply-chain/runr-supply-chain.sh): one protocol
 * line per result, everything else (tool output) is ignored.
 *
 *   RUNR_STEP <step> <ok|failed|skipped> [base64(message)]
 *   RUNR_DIGEST <sha256:...>
 *   RUNR_FILE <name> <base64(gzip(content))>
 *   RUNR_DONE
 */
final class PipelineOutput
{
    /**
     * @param  array<string, array{status: string, message: ?string}>  $steps
     * @param  array<string, string>  $files
     */
    private function __construct(
        public readonly array $steps,
        public readonly ?string $digest,
        public readonly array $files,
        public readonly bool $done,
    ) {}

    public static function parse(?string $log): self
    {
        $steps = [];
        $files = [];
        $digest = null;
        $done = false;

        foreach (preg_split('/\r?\n/', (string) $log) as $line) {
            $line = trim($line);
            if (! str_starts_with($line, 'RUNR_')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 4);
            switch ($parts[0]) {
                case 'RUNR_STEP':
                    if (count($parts) >= 3 && preg_match('/^[a-z][a-z0-9-]*$/', $parts[1])) {
                        $message = isset($parts[3]) ? base64_decode($parts[3], true) : null;
                        $steps[$parts[1]] = [
                            'status' => in_array($parts[2], ['ok', 'failed', 'skipped'], true) ? $parts[2] : 'failed',
                            'message' => $message !== null && $message !== false ? mb_substr(trim(self::stripAnsi($message)), -1000) : null,
                        ];
                    }
                    break;
                case 'RUNR_DIGEST':
                    if (isset($parts[1]) && preg_match('/^sha256:[a-f0-9]{64}$/', $parts[1])) {
                        $digest = $parts[1];
                    }
                    break;
                case 'RUNR_FILE':
                    if (count($parts) >= 3 && preg_match('/^[a-z0-9.-]+$/', $parts[1])) {
                        $raw = base64_decode($parts[2], true);
                        $content = $raw === false ? false : @gzdecode($raw);
                        if ($content !== false) {
                            $files[$parts[1]] = $content;
                        }
                    }
                    break;
                case 'RUNR_DONE':
                    $done = true;
                    break;
            }
        }

        return new self($steps, $digest, $files, $done);
    }

    public function step(string $name): ?array
    {
        return $this->steps[$name] ?? null;
    }

    public function ok(string $name): bool
    {
        return ($this->steps[$name]['status'] ?? null) === 'ok';
    }

    public function file(string $name): ?string
    {
        return $this->files[$name] ?? null;
    }

    private static function stripAnsi(string $s): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $s);
    }
}
