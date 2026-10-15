<?php

namespace App\Services\Bindings\Objects;

use App\Services\Bindings\Ops\OpsTask;

/**
 * S3 operations through rclone (bucket create / size / empty / purge) as OpsTasks; the remote
 * "t" is configured from an env file in /ops (credentials never on the command line).
 */
class Rclone
{
    public static function envFile(string $endpoint, string $region, string $accessKey, string $secretKey, string $provider = 'Other', bool $pathStyle = true): string
    {
        $values = [
            'RCLONE_CONFIG_T_TYPE' => 's3',
            'RCLONE_CONFIG_T_PROVIDER' => $provider,
            'RCLONE_CONFIG_T_ENDPOINT' => $endpoint,
            'RCLONE_CONFIG_T_REGION' => $region,
            'RCLONE_CONFIG_T_ACCESS_KEY_ID' => $accessKey,
            'RCLONE_CONFIG_T_SECRET_ACCESS_KEY' => $secretKey,
            'RCLONE_CONFIG_T_FORCE_PATH_STYLE' => $pathStyle ? 'true' : 'false',
            'RCLONE_CONFIG_T_NO_CHECK_BUCKET' => 'true',
        ];

        $lines = [];
        foreach ($values as $key => $value) {
            $lines[] = $key."='".str_replace("'", "'\\''", $value)."'";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $commands  rclone sub-commands, each run as `rclone <command>`
     */
    public static function task(string $purpose, string $envFile, array $commands, array $extraFiles = []): OpsTask
    {
        $script = ['set -e', 'set -a; . /ops/rclone.env; set +a'];
        foreach ($commands as $command) {
            $script[] = 'rclone '.$command;
        }
        $script[] = 'echo RUNR_OK';
        $script[] = '';

        return new OpsTask($purpose, (string) config('bindings.object.client_image'), implode("\n", $script), ['rclone.env' => $envFile] + $extraFiles);
    }

    /**
     * Sizes of buckets: "USAGE <binding id> <bytes>" lines (rclone size --json).
     *
     * @param  array<string, string>  $buckets  binding id → bucket
     */
    public static function sizes(string $envFile, array $buckets): OpsTask
    {
        $targets = [];
        foreach ($buckets as $id => $bucket) {
            $targets[] = "{$id} {$bucket}";
        }

        $script = implode("\n", [
            'set -a; . /ops/rclone.env; set +a',
            'while read -r id bucket; do',
            '  [ -z "$id" ] && continue',
            '  json=$(rclone size --json "t:$bucket" 2>/dev/null || echo "{}")',
            '  bytes=$(echo "$json" | sed -n \'s/.*"bytes":\([0-9]*\).*/\1/p\')',
            '  echo "USAGE $id ${bytes:-0}"',
            'done < /ops/targets',
            'echo RUNR_OK',
            '',
        ]);

        return new OpsTask('object-usage', (string) config('bindings.object.client_image'), $script, [
            'rclone.env' => $envFile,
            'targets' => implode("\n", $targets)."\n",
        ]);
    }
}
