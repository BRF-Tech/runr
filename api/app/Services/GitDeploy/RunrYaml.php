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
 *   scale:     { min, max, to_zero, schedule: [{ cron, min }] }   schedule: the timed warmer, [] = off
 *   trigger:   { type: http | schedule | queue, schedule: "<cron>", queue: <stream> }
 *   triggers:  [ { type, name?, enabled?, delivery?, …config } ]   the whole trigger list
 *                                   (docs/triggers.md); wins over `trigger:`. Secrets (a
 *                                   webhook's signing secret, broker passwords) are set in RunR
 *   env:       { KEY: value }       non-secret; merged (listed keys set, others stay)
 *   network:   { access: public | allowlist | internal, allow_cidrs: [ranges], callers: [names] }
 *                                   the project's network (docs/internal-network.md); callers
 *                                   replace its allow list; applied for a pusher with network.manage
 *   deploy:    { strategy: direct | blue-green | canary, blue_green: {…}, canary: {…} }
 *                                   the deploy strategy (App\Services\Rollouts\DeployStrategy;
 *                                   only the keys present change)
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
            if (array_key_exists('schedule', $scale)) {
                [$schedule, $scheduleWarnings] = self::scaleSchedule($scale['schedule'], $str);
                $warnings = array_merge($warnings, $scheduleWarnings);
                if ($schedule !== null) {
                    $settings['scale_schedule'] = $schedule;
                }
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

        if (array_key_exists('triggers', $doc)) {
            [$triggers, $triggerWarnings] = self::triggers($doc['triggers']);
            array_push($warnings, ...$triggerWarnings);
            if ($triggers !== null) {
                $settings['triggers'] = $triggers;
                if (isset($settings['trigger_type'])) {
                    $warnings[] = 'runr.yaml: triggers and trigger are both set; triggers is used';
                    unset($settings['trigger_type'], $settings['schedule'], $settings['queue_stream']);
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

        $network = $doc['network'] ?? null;
        if ($network !== null && ! is_array($network)) {
            $warnings[] = 'runr.yaml: network must be a mapping';
        } elseif (is_array($network)) {
            $out = [];
            if (($access = $str($network['access'] ?? null)) !== null) {
                $out['access_mode'] = strtolower($access);
            }
            foreach (['allow_cidrs' => 'allowed_cidrs', 'callers' => 'callers'] as $from => $to) {
                if (array_key_exists($from, $network)) {
                    is_array($network[$from]) && array_is_list($network[$from]) && count(array_filter($network[$from], 'is_scalar')) === count($network[$from])
                        ? $out[$to] = array_map(fn ($v) => trim((string) $v), $network[$from])
                        : $warnings[] = "runr.yaml: network.{$from} must be a list of strings";
                }
            }
            if ($out) {
                $settings['network'] = $out;
            }
        }

        $deploy = $doc['deploy'] ?? null;
        if ($deploy !== null) {
            if (! is_array($deploy) || ($deploy !== [] && array_is_list($deploy))) {
                $warnings[] = 'runr.yaml: deploy must be a mapping (strategy, blue_green, canary)';
            } else {
                $strategy = [];
                if (array_key_exists('strategy', $deploy)) {
                    $strategy['strategy'] = is_scalar($deploy['strategy']) ? trim((string) $deploy['strategy']) : $deploy['strategy'];
                }
                foreach (['blue_green' => ['blue_green', 'blue-green', 'bluegreen'], 'canary' => ['canary']] as $group => $keys) {
                    foreach ($keys as $key) {
                        if (! array_key_exists($key, $deploy)) {
                            continue;
                        }
                        is_array($deploy[$key]) && ($deploy[$key] === [] || ! array_is_list($deploy[$key]))
                            ? $strategy[$group] = $deploy[$key]
                            : $warnings[] = "runr.yaml: deploy.{$key} must be a mapping";
                        break;
                    }
                }
                if ($strategy !== []) {
                    $settings['deploy_strategy'] = $strategy;
                }
            }
        }

        return ['settings' => $settings, 'warnings' => $warnings];
    }

    /**
     * scale.schedule — the timed warmer (docs/scaling.md#timed-warmer): a list of {cron, min} rows
     * ([] / null turns it off). Applied only when every row has a cron and a whole-number min;
     * RunrYamlApplier validates the rows as the API does.
     *
     * @param  callable(mixed): ?string  $str
     * @return array{0: list<array{cron: string, min_scale: int}>|null, 1: list<string>} the rows (null: leave the schedule as it is), warnings
     */
    private static function scaleSchedule(mixed $schedule, callable $str): array
    {
        if ($schedule === null || $schedule === []) {
            return [[], []];
        }
        if (! is_array($schedule) || ! array_is_list($schedule)) {
            return [null, ['runr.yaml: scale.schedule must be a list of {cron, min} rows']];
        }

        $rows = [];
        $warnings = [];
        foreach ($schedule as $i => $row) {
            $cron = is_array($row) ? $str($row['cron'] ?? null) : null;
            $min = is_array($row) ? ($row['min'] ?? null) : null;
            if ($cron === null || ! ((is_int($min) && $min >= 0) || (is_string($min) && ctype_digit($min)))) {
                $warnings[] = "runr.yaml: scale.schedule[{$i}] needs a cron and a whole-number min";

                continue;
            }
            $rows[] = ['cron' => $cron, 'min_scale' => (int) $min];
        }

        return [$warnings === [] ? $rows : null, $warnings];
    }

    /**
     * `triggers:` — a list of mappings: type (required), name, enabled and delivery are trigger
     * fields, every other key goes into the trigger's config. Shorthands of the single `trigger:`
     * block: schedule → cron (schedule type), queue → stream (queue type).
     *
     * @return array{0: list<array<string, mixed>>|null, 1: list<string>}
     */
    private static function triggers(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
            return [null, ['runr.yaml: triggers must be a non-empty list of mappings']];
        }

        $list = [];
        $warnings = [];
        foreach ($raw as $i => $item) {
            $type = is_array($item) && is_scalar($item['type'] ?? null) ? strtolower(trim((string) $item['type'])) : '';
            if ($type === '') {
                $warnings[] = "runr.yaml: triggers[{$i}] needs a type";

                continue;
            }

            $trigger = ['type' => $type];
            if (is_scalar($item['name'] ?? null) && trim((string) $item['name']) !== '') {
                $trigger['name'] = trim((string) $item['name']);
            }
            if (array_key_exists('enabled', $item)) {
                $trigger['enabled'] = (bool) $item['enabled'];
            }

            $config = array_diff_key($item, array_flip(['type', 'name', 'enabled', 'secrets']));
            if ($type === 'schedule' && isset($config['schedule']) && ! isset($config['cron'])) {
                $config['cron'] = $config['schedule'];
                unset($config['schedule']);
            }
            if ($type === 'queue' && isset($config['queue']) && ! isset($config['stream'])) {
                $config['stream'] = $config['queue'];
                unset($config['queue']);
            }
            if (array_key_exists('secrets', $item)) {
                $warnings[] = "runr.yaml: triggers[{$i}].secrets is ignored (secrets are set in RunR, not in runr.yaml)";
            }
            $trigger['config'] = $config;
            $list[] = $trigger;
        }

        return [$list === [] ? null : $list, $warnings];
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
