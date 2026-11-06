<?php

namespace App\Services\Helpers;

use App\Services\Contracts\KnativeConstants;
use stdClass;

/**
 * The values of a region Traefik release on its way to the pinned chart (TraefikChartStep).
 *
 * A Helm release keeps the values it was given, and chart 41 moved the logging keys: `logs.general`
 * is `log`, `logs.access` is `accessLog`, `fields.general.*` is `fields.*`, and the keys of filters
 * and fields are camelCase. Its schema refuses the old root key, so `helm upgrade --reuse-values`
 * cannot cross that version. The rest of what RunR gives the chart keeps its meaning up to the
 * pinned one: with these keys moved it renders the same container arguments, RBAC and Services.
 *
 * The move also sets what a new region is given and an earlier chart cannot: request headers with
 * an aliasing name are deleted on every entry point (KnativeConstants::TRAEFIK_ALIAS_HEADERS_STRATEGY),
 * or kept where the operator chose that as a stopgap (aliasStrategy()). As a global value it holds
 * for an entry point added later too (PrivateIngressStep's internal), and no step that writes
 * ports.* has to carry it.
 *
 * Works on decoded JSON objects, not arrays: the chart's schema tells {} from [] (an empty PHP
 * array would become one of them for every value), and `helm get values -o json` keeps them apart.
 */
final class TraefikChartValues
{
    /** The operator's stopgap: Traefik's own default, which reopens what delete closes */
    public const ALIAS_KEEP = 'keep';

    /** The Traefik container's argument (followed by the strategy) and the chart value behind it */
    public const ALIAS_HEADERS_ARG = '--global.aliasHeadersStrategy=';

    public const ALIAS_HEADERS_KEY = 'global.aliasHeadersStrategy';

    /** The first chart major a release moves from: earlier ones, which RunR never pinned, stay. */
    public const FROM_MAJOR = 40;

    /** Keys of accessLog that went from lowercase to camelCase */
    private const CAMEL_CASED = [
        'statuscodes' => 'statusCodes',
        'retryattempts' => 'retryAttempts',
        'minduration' => 'minDuration',
        'defaultmode' => 'defaultMode',
    ];

    /** Does a release of this chart version move to the pinned chart? */
    public static function movable(string $deployed): bool
    {
        return (int) ltrim($deployed, 'v') >= self::FROM_MAJOR
            && version_compare(ltrim($deployed, 'v'), KnativeConstants::TRAEFIK_CHART_VERSION, '<');
    }

    /**
     * The strategy of this installation (Helm value knative.traefikAliasHeadersStrategy). Only keep
     * asked for by name leaves the default: anything else deletes.
     */
    public static function aliasStrategy(): string
    {
        return config('knative.traefik_alias_headers_strategy') === self::ALIAS_KEEP
            ? self::ALIAS_KEEP
            : KnativeConstants::TRAEFIK_ALIAS_HEADERS_STRATEGY;
    }

    /**
     * The strategy a Traefik container runs, from its args. Without the argument it is Traefik's
     * default, keep.
     *
     * @param  list<string>  $args
     */
    public static function runningAliasStrategy(array $args): string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, self::ALIAS_HEADERS_ARG)) {
                return substr($arg, strlen(self::ALIAS_HEADERS_ARG));
            }
        }

        return self::ALIAS_KEEP;
    }

    /**
     * Can this image take the alias strategy? The chart refuses it on an older Traefik version; a
     * reference without a version runs the chart's own, a tag that is none is for the chart to refuse.
     */
    public static function handlesAliases(string $imageRef): bool
    {
        $version = TraefikImage::version($imageRef);

        return preg_match('/^v?\d/', $version) !== 1
            || version_compare(ltrim($version, 'v'), ltrim(KnativeConstants::TRAEFIK_ALIAS_HEADERS_SINCE, 'v'), '>=');
    }

    /** A release's values as the pinned chart takes them, running $imageRef with $aliasStrategy. */
    public static function forPinnedChart(stdClass $values, string $imageRef, string $aliasStrategy = KnativeConstants::TRAEFIK_ALIAS_HEADERS_STRATEGY): stdClass
    {
        $logs = $values->logs ?? null;
        unset($values->logs);

        $global = $values->global ?? new stdClass;
        $global->aliasHeadersStrategy = $aliasStrategy;
        $values->global = $global;

        if ($logs instanceof stdClass) {
            if (isset($logs->general)) {
                $values->log = $logs->general;
            }
            if (isset($logs->access)) {
                $values->accessLog = self::accessLog($logs->access);
            }
        }

        $image = (array) ($values->image ?? []);
        unset($image['tag'], $image['digest']);
        $values->image = (object) (TraefikImage::helmValues($imageRef) + $image);

        return $values;
    }

    private static function accessLog(stdClass $access): stdClass
    {
        $access = self::camelCased($access);

        if (($access->fields->general ?? null) instanceof stdClass) {
            foreach (get_object_vars($access->fields->general) as $key => $value) {
                $access->fields->{$key} = $value;
            }
            unset($access->fields->general);
        }

        return $access;
    }

    private static function camelCased(stdClass $values): stdClass
    {
        $renamed = new stdClass;
        foreach (get_object_vars($values) as $key => $value) {
            $renamed->{self::CAMEL_CASED[$key] ?? $key} = $value instanceof stdClass ? self::camelCased($value) : $value;
        }

        return $renamed;
    }
}
