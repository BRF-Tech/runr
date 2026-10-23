<?php

namespace App\Services\OrgTransfer\Region;

/**
 * The tool container of a transfer pod: an image with the client (pg_dump, redis-cli, rclone),
 * a shell script (/ops/run.sh) and files next to it in /ops — credentials go into these files
 * (a short-lived Secret), never into the pod spec or a command line.
 *
 * Sending: the script writes its files into /work/out/ and ends with /work/.done (or writes
 * /work/.failed with the reason). Receiving: the script waits for /work/in/.ready, restores the
 * files in /work/in/, then writes /work/.done or /work/.failed.
 */
final class TransferTool
{
    /**
     * @param  array<string, string>  $files  /ops/<name> => content
     * @param  list<array<string, mixed>>  $env  container env entries
     * @param  list<string>|null  $command  run instead of `sh /ops/run.sh` (an image without a shell)
     */
    public function __construct(
        public readonly string $image,
        public readonly string $script,
        public readonly array $files = [],
        public readonly array $env = [],
        public readonly ?array $command = null,
    ) {}

    /** POSIX shell single-quoting (escapeshellarg differs on Windows). */
    public static function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    /** Lines that end a script: the marker the agent waits for, or the reason it failed. */
    public static function wrap(string $body): string
    {
        return implode("\n", [
            '#!/bin/sh',
            'set -u',
            'export HOME=/tmp',
            'mkdir -p /work/out /work/in',
            'fail() { printf "%s" "$1" > /work/.failed; echo "RUNR_FAILED $1" >&2; exit 1; }',
            $body,
            'touch /work/.done',
            '',
        ]);
    }
}
