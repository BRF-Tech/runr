<?php

namespace App\Services\Bindings\Ops;

/**
 * One in-cluster operation: a shell script (run.sh) plus files mounted at /ops from a
 * short-lived Secret — credentials never appear in the pod spec or in its arguments.
 */
final class OpsTask
{
    /**
     * @param  array<string, string>  $files  extra files in /ops (name → content)
     * @param  list<array>  $env  container env entries (e.g. secretKeyRef)
     */
    public function __construct(
        public string $purpose,
        public string $image,
        public string $script,
        public array $files = [],
        public array $env = [],
        public ?int $timeout = null,
    ) {}

    /** POSIX shell single-quoting (escapeshellarg differs on Windows). */
    public static function quote(string $value): string
    {
        return "'".str_replace("'", "'\''", $value)."'";
    }
}
