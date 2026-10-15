<?php

namespace App\Services\Observability\Tracing;

use Illuminate\Support\Carbon;

/**
 * Tempo answers → the shapes of the Traces tab.
 *
 *  - search(): GET /api/search — traceID (hex), rootServiceName, rootTraceName, startTimeUnixNano,
 *    durationMs, spanSet(s) with the selected attributes
 *  - trace():  GET /api/traces/{id} (OTLP JSON: batches / resourceSpans, scopeSpans /
 *    instrumentationLibrarySpans; ids hex or base64 depending on the Tempo version) — a flat span
 *    list ordered for a waterfall (start offset, depth, parent), attributes, events, errors
 */
final class TraceNormalizer
{
    private const KINDS = [
        0 => 'unspecified', 1 => 'internal', 2 => 'server', 3 => 'client', 4 => 'producer', 5 => 'consumer',
    ];

    private const STATUS = [0 => 'unset', 1 => 'ok', 2 => 'error'];

    /** @return list<array> */
    public static function searchResults(array $response): array
    {
        $out = [];
        foreach ($response['traces'] ?? [] as $t) {
            $spans = [];
            foreach (array_merge(isset($t['spanSet']) ? [$t['spanSet']] : [], $t['spanSets'] ?? []) as $set) {
                foreach ($set['spans'] ?? [] as $s) {
                    $id = self::hexId((string) ($s['spanID'] ?? ''));
                    $spans[$id] = [
                        'span_id' => $id,
                        'duration_ms' => round(((int) ($s['durationNanos'] ?? 0)) / 1e6, 3),
                        'attributes' => self::attributes($s['attributes'] ?? []),
                    ];
                }
            }
            $spans = array_values($spans);

            $status = null;
            $path = null;
            $cold = null;
            foreach ($spans as $s) {
                $status ??= isset($s['attributes']['http.response.status_code']) ? (int) $s['attributes']['http.response.status_code']
                    : (isset($s['attributes']['http.status_code']) ? (int) $s['attributes']['http.status_code'] : null);
                $path ??= $s['attributes']['url.path'] ?? $s['attributes']['http.target'] ?? null;
                $cold ??= $s['attributes']['runr.cold_start'] ?? null;
            }

            $startNs = (string) ($t['startTimeUnixNano'] ?? '0');
            $out[] = [
                'trace_id' => self::hexId((string) ($t['traceID'] ?? '')),
                'root_service' => $t['rootServiceName'] ?? null,
                'root_name' => $t['rootTraceName'] ?? null,
                'start' => self::iso($startNs),
                'start_ms' => self::nsToMs($startNs),
                'duration_ms' => isset($t['durationMs']) ? (float) $t['durationMs'] : null,
                'status_code' => $status,
                'path' => $path,
                'cold_start' => $cold,
                'error' => $status !== null && $status >= 500,
                'matched' => (int) ($t['spanSet']['matched'] ?? count($spans)),
                'spans' => $spans,
            ];
        }

        usort($out, fn ($a, $b) => $b['start_ms'] <=> $a['start_ms']);

        return $out;
    }

    /**
     * Flat span list of one trace; null when the answer holds no spans.
     */
    public static function trace(array $response): ?array
    {
        $batches = $response['batches'] ?? $response['resourceSpans'] ?? $response['trace']['resourceSpans'] ?? [];

        $spans = [];
        foreach ($batches as $batch) {
            $resource = self::attributes($batch['resource']['attributes'] ?? []);
            $service = (string) ($resource['service.name'] ?? 'unknown');
            foreach (array_merge($batch['scopeSpans'] ?? [], $batch['instrumentationLibrarySpans'] ?? []) as $scope) {
                $scopeName = $scope['scope']['name'] ?? $scope['instrumentationLibrary']['name'] ?? null;
                foreach ($scope['spans'] ?? [] as $s) {
                    $spans[] = [
                        'trace_id' => self::hexId((string) ($s['traceId'] ?? '')),
                        'span_id' => self::hexId((string) ($s['spanId'] ?? '')),
                        'parent_span_id' => ($s['parentSpanId'] ?? '') !== '' ? self::hexId((string) $s['parentSpanId']) : null,
                        'name' => (string) ($s['name'] ?? ''),
                        'kind' => self::kind($s['kind'] ?? 0),
                        'service' => $service,
                        'scope' => $scopeName,
                        'start_ns' => (string) ($s['startTimeUnixNano'] ?? '0'),
                        'end_ns' => (string) ($s['endTimeUnixNano'] ?? '0'),
                        'status' => [
                            'code' => self::status($s['status']['code'] ?? 0),
                            'message' => $s['status']['message'] ?? null,
                        ],
                        'attributes' => self::attributes($s['attributes'] ?? []),
                        'events' => array_map(fn ($e) => [
                            'name' => (string) ($e['name'] ?? ''),
                            'time_ns' => (string) ($e['timeUnixNano'] ?? '0'),
                            'attributes' => self::attributes($e['attributes'] ?? []),
                        ], $s['events'] ?? []),
                        'resource' => $resource,
                    ];
                }
            }
        }

        if (! $spans) {
            return null;
        }

        $start = min(array_map(fn ($s) => self::ns($s['start_ns']), $spans));
        $end = max(array_map(fn ($s) => self::ns($s['end_ns']), $spans));

        $ids = array_flip(array_column($spans, 'span_id'));
        foreach ($spans as &$s) {
            $s['start_offset_ms'] = round((self::ns($s['start_ns']) - $start) / 1e6, 3);
            $s['duration_ms'] = round(max(0, self::ns($s['end_ns']) - self::ns($s['start_ns'])) / 1e6, 3);
            foreach ($s['events'] as &$e) {
                $e['offset_ms'] = round((self::ns($e['time_ns']) - $start) / 1e6, 3);
            }
            unset($e);
            // a parent that is not part of the answer (sampled out upstream) makes the span a root
            if ($s['parent_span_id'] !== null && ! isset($ids[$s['parent_span_id']])) {
                $s['missing_parent'] = true;
            }
        }
        unset($s);

        $ordered = self::waterfall($spans);

        $services = array_values(array_unique(array_column($ordered, 'service')));
        $errors = count(array_filter($ordered, fn ($s) => $s['status']['code'] === 'error'));

        return [
            'trace_id' => $ordered[0]['trace_id'],
            'start' => self::iso((string) $start),
            'start_ms' => intdiv($start, 1_000_000),
            'end_ms' => intdiv($end, 1_000_000),
            'duration_ms' => round(($end - $start) / 1e6, 3),
            'span_count' => count($ordered),
            'error_count' => $errors,
            'services' => $services,
            'spans' => $ordered,
        ];
    }

    /**
     * Depth-first order (children by start time under their parent) with the depth of each span.
     *
     * @param  list<array>  $spans
     * @return list<array>
     */
    private static function waterfall(array $spans): array
    {
        $byParent = [];
        $ids = array_flip(array_column($spans, 'span_id'));
        foreach ($spans as $s) {
            $parent = $s['parent_span_id'] !== null && isset($ids[$s['parent_span_id']]) ? $s['parent_span_id'] : '';
            $byParent[$parent][] = $s;
        }
        foreach ($byParent as &$children) {
            usort($children, fn ($a, $b) => self::ns($a['start_ns']) <=> self::ns($b['start_ns']));
        }
        unset($children);

        $out = [];
        $seen = [];
        $walk = function (string $parent, int $depth) use (&$walk, &$out, &$seen, $byParent) {
            foreach ($byParent[$parent] ?? [] as $s) {
                if (isset($seen[$s['span_id']])) {
                    continue;
                }
                $seen[$s['span_id']] = true;
                $s['depth'] = $depth;
                $out[] = $s;
                $walk($s['span_id'], $depth + 1);
            }
        };
        $walk('', 0);

        // spans in a parent cycle (malformed data) are appended as roots
        foreach ($spans as $s) {
            if (! isset($seen[$s['span_id']])) {
                $s['depth'] = 0;
                $out[] = $s;
            }
        }

        return $out;
    }

    /** OTLP key/values → [key => scalar|list]. */
    public static function attributes(array $kvs): array
    {
        $out = [];
        foreach ($kvs as $kv) {
            if (! isset($kv['key'])) {
                continue;
            }
            $out[(string) $kv['key']] = self::value($kv['value'] ?? []);
        }

        return $out;
    }

    private static function value(array $v): mixed
    {
        return match (true) {
            array_key_exists('stringValue', $v) => (string) $v['stringValue'],
            array_key_exists('boolValue', $v) => (bool) $v['boolValue'],
            array_key_exists('intValue', $v) => (int) $v['intValue'],
            array_key_exists('doubleValue', $v) => (float) $v['doubleValue'],
            array_key_exists('arrayValue', $v) => array_map(fn ($x) => self::value($x), $v['arrayValue']['values'] ?? []),
            array_key_exists('kvlistValue', $v) => self::attributes($v['kvlistValue']['values'] ?? []),
            default => null,
        };
    }

    /** Ids are hex in the search API and base64 in the protobuf JSON of older Tempo versions. */
    public static function hexId(string $id): string
    {
        if ($id === '' || preg_match('/^[0-9a-fA-F]+$/', $id)) {
            $hex = strtolower($id);

            // Tempo's search API drops leading zeros of trace ids
            return strlen($hex) > 16 && strlen($hex) < 32 ? str_pad($hex, 32, '0', STR_PAD_LEFT) : $hex;
        }

        $raw = base64_decode($id, true);

        return $raw === false ? strtolower($id) : bin2hex($raw);
    }

    private static function kind(mixed $kind): string
    {
        if (is_string($kind) && ! is_numeric($kind)) {
            return strtolower(str_replace('SPAN_KIND_', '', $kind));
        }

        return self::KINDS[(int) $kind] ?? 'unspecified';
    }

    private static function status(mixed $code): string
    {
        if (is_string($code) && ! is_numeric($code)) {
            return strtolower(str_replace('STATUS_CODE_', '', $code));
        }

        return self::STATUS[(int) $code] ?? 'unset';
    }

    private static function ns(string $ns): int
    {
        return (int) $ns;
    }

    private static function nsToMs(string $ns): int
    {
        return intdiv((int) $ns, 1_000_000);
    }

    private static function iso(string $ns): ?string
    {
        $ms = intdiv((int) $ns, 1_000_000);

        return $ms > 0 ? Carbon::createFromTimestampMs($ms)->toIso8601ZuluString('millisecond') : null;
    }
}
