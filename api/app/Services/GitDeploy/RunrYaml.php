<?php

namespace App\Services\GitDeploy;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * runr.yaml (or runr.yml) of a pushed tree — the CLI's project file (docs/cli.md, "runr.yaml";
 * cli/internal/projectfile). The server reads the same keys; unknown keys are ignored here
 * (the CLI rejects them), every key is optional and only the keys present are applied:
 *
 *   name, type, runtime, region     compared with the project (a push never renames, retypes
 *                                   or moves a project; a difference is a warning)
 *   runtime_version, description
 *   handler                         faas "file.function", xaas the function name
 *   file                            xaas: the script
 *   image, command, args, ports     caas
 *   resources: { cpu, memory_mb, timeout_seconds }
 *   scale:     { min, max, to_zero }
 *   trigger:   { type: http | schedule | queue, schedule: "<cron>", queue: <stream> }
 *   env:       { KEY: value }       non-secret; merged (listed keys set, others stay)
 *   ignore:    [patterns]           files that are not part of the version (SourceTree)
 *   dev:                            local only (runr dev), ignored
 */
final class RunrYaml
{
    /**
     * @return array{settings: array<string, mixed>, warnings: list<string>}
     */
    public static function parse(?string $source): array
    {
        [$doc, $warnings] = self::document($source);
        if ($doc === null) {
            return ['settings' => [], 'warnings' => $warnings];
        }

        $settings = [];
        $str = fn ($v) => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;

        foreach (['name', 'type', 'runtime', 'runtime_version', 'region', 'description', 'handler', 'file', 'image'] as $key) {
            if (array_key_exists($key, $doc) && ($v = $str($doc[$key])) !== null) {
                $settings[$key] = $v;
            }
        }

        foreach (['command', 'args'] as $key) {
            if (array_key_exists($key, $doc)) {
                is_array($doc[$key]) && array_is_list($doc[$key]) && count(array_filter($doc[$key], 'is_scalar')) === count($doc[$key])
                    ? $settings[$key] = array_map('strval', $doc[$key])
                    : $warnings[] = "runr.yaml: {$key} must be a list of strings";
            }
        }
        if (array_key_exists('ports', $doc)) {
            $ports = is_array($doc['ports']) ? $doc['ports'] : [];
            $valid = array_filter($ports, fn ($p) => is_int($p) || (is_string($p) && ctype_digit($p)));
            $ports && count($valid) === count($ports)
                ? $settings['ports'] = array_map('intval', $ports)
                : $warnings[] = 'runr.yaml: ports must be a list of port numbers';
        }

        $resources = $doc['resources'] ?? null;
        if ($resources !== null && ! is_array($resources)) {
            $warnings[] = 'runr.yaml: resources must be a mapping';
            $resources = null;
        }
        if ($resources) {
            if (($cpu = $str($resources['cpu'] ?? null)) !== null) {
                $settings['cpu'] = $cpu;
            }
            foreach (['memory_mb', 'timeout_seconds'] as $key) {
                if (array_key_exists($key, $resources)) {
                    is_int($resources[$key]) || (is_string($resources[$key]) && ctype_digit($resources[$key]))
                        ? $settings[$key] = (int) $resources[$key]
                        : $warnings[] = "runr.yaml: resources.{$key} must be a whole number";
                }
            }
        }

        $scale = $doc['scale'] ?? null;
        if ($scale !== null && ! is_array($scale)) {
            $warnings[] = 'runr.yaml: scale must be a mapping';
            $scale = null;
        }
        if ($scale) {
            foreach (['min' => 'min_scale', 'max' => 'max_scale'] as $from => $to) {
                if (array_key_exists($from, $scale)) {
                    (is_int($scale[$from]) && $scale[$from] >= 0) || (is_string($scale[$from]) && ctype_digit($scale[$from]))
                        ? $settings[$to] = (int) $scale[$from]
                        : $warnings[] = "runr.yaml: scale.{$from} must be a whole number";
                }
            }
            if (array_key_exists('to_zero', $scale)) {
                is_bool($scale['to_zero'])
                    ? $settings['scale_to_zero'] = $scale['to_zero']
                    : $warnings[] = 'runr.yaml: scale.to_zero must be true or false';
            }
        }

        $trigger = $doc['trigger'] ?? null;
        if ($trigger !== null) {
            if (! is_array($trigger) || ($type = $str($trigger['type'] ?? null)) === null) {
                $warnings[] = 'runr.yaml: trigger needs a type (http, schedule or queue)';
            } else {
                $settings['trigger_type'] = strtolower($type);
                if (($schedule = $str($trigger['schedule'] ?? null)) !== null) {
                    $settings['schedule'] = $schedule;
                }
                if (($queue = $str($trigger['queue'] ?? null)) !== null) {
                    $settings['queue_stream'] = $queue;
                }
            }
        }

        if (array_key_exists('env', $doc)) {
            if (! is_array($doc['env']) || ($doc['env'] !== [] && array_is_list($doc['env']))) {
                $warnings[] = 'runr.yaml: env must be a mapping of names to values';
            } else {
                $env = [];
                foreach ($doc['env'] as $key => $value) {
                    if (is_array($value) || is_object($value)) {
                        $warnings[] = "runr.yaml: env.{$key} is not a plain value (secrets are set in RunR, not in runr.yaml)";

                        continue;
                    }
                    $env[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                }
                $settings['env'] = $env;
            }
        }

        return ['settings' => $settings, 'warnings' => $warnings];
    }

    /**
     * The `ignore:` patterns of a project file (malformed files have none).
     *
     * @return list<string>
     */
    public static function ignorePatterns(?string $source): array
    {
        [$doc] = self::document($source);
        $ignore = $doc['ignore'] ?? null;

        if (! is_array($ignore)) {
            return is_string($ignore) && trim($ignore) !== '' ? [trim($ignore)] : [];
        }

        return array_values(array_filter(array_map(fn ($p) => is_scalar($p) ? trim((string) $p) : '', $ignore), fn ($p) => $p !== ''));
    }

    /**
     * @return array{0: ?array, 1: list<string>}
     */
    private static function document(?string $source): array
    {
        if ($source === null || trim($source) === '') {
            return [null, []];
        }

        try {
            $doc = Yaml::parse($source);
        } catch (ParseException $e) {
            return [null, ['runr.yaml is not valid YAML: '.$e->getMessage()]];
        }

        if (! is_array($doc) || ($doc !== [] && array_is_list($doc))) {
            return [null, ['runr.yaml must be a mapping']];
        }

        return [$doc, []];
    }
}
