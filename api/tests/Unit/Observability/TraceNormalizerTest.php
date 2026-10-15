<?php

namespace Tests\Unit\Observability;

use App\Services\Observability\Tracing\TraceNormalizer;
use App\Services\Observability\Tracing\TraceQueryService;
use Tests\TestCase;

/**
 * Tempo answers → Traces tab shapes (search list, waterfall with depth / offsets / events /
 * errors, hex and base64 ids) and the TraceQL of the list filters.
 */
class TraceNormalizerTest extends TestCase
{
    public static function otlpTrace(string $projectId = '01JPROJECT0000000000000000', bool $base64Ids = false): array
    {
        $tid = '4bf92f3577b34da6a3ce929d0e0e4736';
        $id = fn (string $hex) => $base64Ids ? base64_encode(hex2bin($hex)) : $hex;
        $t0 = 1_760_000_000_000_000_000;

        return ['batches' => [
            [
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'activator']],
                ]],
                'scopeSpans' => [['scope' => ['name' => 'knative'], 'spans' => [[
                    'traceId' => $id($tid), 'spanId' => $id('aaaaaaaaaaaaaaaa'), 'name' => 'activator_proxy',
                    'kind' => 'SPAN_KIND_SERVER', 'startTimeUnixNano' => (string) $t0, 'endTimeUnixNano' => (string) ($t0 + 50_000_000),
                    'attributes' => [], 'status' => ['code' => 'STATUS_CODE_UNSET'],
                ]]]],
            ],
            [
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'orders']],
                    ['key' => 'runr.project_id', 'value' => ['stringValue' => $projectId]],
                ]],
                'scopeSpans' => [['scope' => ['name' => 'runr-wrapper'], 'spans' => [
                    [
                        'traceId' => $id($tid), 'spanId' => $id('bbbbbbbbbbbbbbbb'), 'parentSpanId' => $id('aaaaaaaaaaaaaaaa'), 'name' => 'GET',
                        'kind' => 2, 'startTimeUnixNano' => (string) ($t0 + 5_000_000), 'endTimeUnixNano' => (string) ($t0 + 45_000_000),
                        'attributes' => [
                            ['key' => 'http.response.status_code', 'value' => ['intValue' => '500']],
                            ['key' => 'runr.cold_start', 'value' => ['boolValue' => true]],
                            ['key' => 'url.path', 'value' => ['stringValue' => '/orders']],
                        ],
                        'events' => [['name' => 'exception', 'timeUnixNano' => (string) ($t0 + 44_000_000), 'attributes' => [
                            ['key' => 'exception.message', 'value' => ['stringValue' => 'database unreachable']],
                        ]]],
                        'status' => ['code' => 2, 'message' => 'HTTP 500'],
                    ],
                    [
                        'traceId' => $id($tid), 'spanId' => $id('cccccccccccccccc'), 'parentSpanId' => $id('bbbbbbbbbbbbbbbb'), 'name' => 'SELECT orders',
                        'kind' => 3, 'startTimeUnixNano' => (string) ($t0 + 10_000_000), 'endTimeUnixNano' => (string) ($t0 + 40_000_000),
                        'attributes' => [['key' => 'db.system', 'value' => ['stringValue' => 'postgresql']]],
                        'status' => [],
                    ],
                ]]],
            ],
        ]];
    }

    public function test_trace_becomes_a_waterfall(): void
    {
        $trace = TraceNormalizer::trace(self::otlpTrace());

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $trace['trace_id']);
        $this->assertSame(3, $trace['span_count']);
        $this->assertSame(1, $trace['error_count']);
        $this->assertEquals(50.0, $trace['duration_ms']);
        $this->assertSame(['activator', 'orders'], $trace['services']);

        [$root, $server, $db] = $trace['spans'];
        $this->assertSame(['aaaaaaaaaaaaaaaa', 0, 0.0], [$root['span_id'], $root['depth'], $root['start_offset_ms']]);
        $this->assertSame(['bbbbbbbbbbbbbbbb', 1, 5.0, 40.0], [$server['span_id'], $server['depth'], $server['start_offset_ms'], $server['duration_ms']]);
        $this->assertSame(['cccccccccccccccc', 2, 'client'], [$db['span_id'], $db['depth'], $db['kind']]);

        $this->assertSame('server', $server['kind']);
        $this->assertSame(['code' => 'error', 'message' => 'HTTP 500'], $server['status']);
        $this->assertSame(500, $server['attributes']['http.response.status_code']);
        $this->assertTrue($server['attributes']['runr.cold_start']);
        $this->assertSame('exception', $server['events'][0]['name']);
        $this->assertEquals(44.0, $server['events'][0]['offset_ms']);
        $this->assertSame('database unreachable', $server['events'][0]['attributes']['exception.message']);
        $this->assertSame('orders', $server['service']);
        $this->assertSame('unset', $root['status']['code']);
    }

    public function test_base64_ids_of_older_tempo_versions(): void
    {
        $trace = TraceNormalizer::trace(self::otlpTrace(base64Ids: true));

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $trace['trace_id']);
        $this->assertSame(['aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb', 'cccccccccccccccc'], array_column($trace['spans'], 'span_id'));
    }

    public function test_resource_spans_shape_and_missing_parent(): void
    {
        $raw = self::otlpTrace();
        array_shift($raw['batches']); // the activator span was sampled out
        $trace = TraceNormalizer::trace(['trace' => ['resourceSpans' => $raw['batches']]]);

        $this->assertSame(0, $trace['spans'][0]['depth']);
        $this->assertTrue($trace['spans'][0]['missing_parent']);
        $this->assertNull(TraceNormalizer::trace(['batches' => []]));
    }

    public function test_search_results(): void
    {
        $list = TraceNormalizer::searchResults(['traces' => [
            [
                'traceID' => '4bf92f3577b34da6a3ce929d0e0e47', // leading zeros dropped by Tempo
                'rootServiceName' => 'orders', 'rootTraceName' => 'GET',
                'startTimeUnixNano' => '1760000000000000000', 'durationMs' => 42,
                'spanSet' => ['matched' => 1, 'spans' => [[
                    'spanID' => 'bbbbbbbbbbbbbbbb', 'durationNanos' => '40000000',
                    'attributes' => [
                        ['key' => 'http.response.status_code', 'value' => ['intValue' => '503']],
                        ['key' => 'url.path', 'value' => ['stringValue' => '/orders']],
                        ['key' => 'runr.cold_start', 'value' => ['boolValue' => false]],
                    ],
                ]]],
            ],
            ['traceID' => '1111111111111111aaaaaaaaaaaaaaaa', 'rootServiceName' => 'orders', 'startTimeUnixNano' => '1760000001000000000', 'durationMs' => 3],
        ]]);

        $this->assertCount(2, $list);
        $this->assertSame('1111111111111111aaaaaaaaaaaaaaaa', $list[0]['trace_id'], 'newest first');
        $this->assertSame('004bf92f3577b34da6a3ce929d0e0e47', $list[1]['trace_id']);
        $this->assertSame(503, $list[1]['status_code']);
        $this->assertTrue($list[1]['error']);
        $this->assertSame('/orders', $list[1]['path']);
        $this->assertFalse($list[1]['cold_start']);
        $this->assertEquals(42.0, $list[1]['duration_ms']);
        $this->assertSame('2025-10-09T08:53:20.000Z', $list[1]['start']);
    }

    public function test_traceql_of_the_filters(): void
    {
        $base = '{ resource.runr.project_id = "01JP" && kind = server';
        $select = ' } | select(span.http.response.status_code, span.http.status_code, span.url.path, span.http.target, span.runr.cold_start)';

        $this->assertSame($base.$select, TraceQueryService::traceQl('01JP', []));
        $this->assertSame($base.' && status = error'.$select, TraceQueryService::traceQl('01JP', ['status' => 'error']));
        $this->assertSame($base.' && status != error && duration >= 250.5ms'.$select, TraceQueryService::traceQl('01JP', ['status' => 'ok', 'min_duration_ms' => '250.5']));
        $this->assertSame(
            $base.' && (span.url.path =~ ".*/api/v1\\\\.orders\\\\?.*" || span.http.target =~ ".*/api/v1\\\\.orders\\\\?.*")'.$select,
            TraceQueryService::traceQl('01JP', ['path' => '/api/v1.orders?']),
        );
        // a quote in the input cannot leave the string literal
        $this->assertStringContainsString('\\"', TraceQueryService::traceQl('01JP', ['path' => 'a"b']));
    }
}
