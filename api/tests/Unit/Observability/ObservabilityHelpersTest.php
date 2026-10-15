<?php

namespace Tests\Unit\Observability;

use App\Models\Observability\UptimeCheck;
use App\Services\Dns\DnsResolver;
use App\Services\Observability\IngestUrl;
use App\Services\Observability\ObservabilityPodSpec;
use App\Services\Observability\OutboundUrlGuard;
use App\Services\Observability\Replay\ReplayService;
use App\Services\Observability\Replay\ResponseDiff;
use App\Services\Observability\ReplayTokens;
use App\Services\Observability\Uptime\UptimeService;
use Tests\TestCase;

/**
 * Pure helpers: response diff, redaction, body limits, replay keys, ingest URL, the SSRF guard,
 * expected-status matching and the uptime location aggregation.
 */
class ObservabilityHelpersTest extends TestCase
{
    public function test_response_diff(): void
    {
        $diff = ResponseDiff::compare(
            ['status' => 500, 'headers' => ['content-type' => 'application/json', 'date' => 'Mon', 'x-old' => '1'], 'body' => '{"error":"boom","id":7}'],
            ['status' => 200, 'headers' => ['Content-Type' => 'application/json; charset=utf-8', 'date' => 'Tue', 'x-new' => '2'], 'body' => '{"id":7,"ok":true}'],
        );

        $this->assertTrue($diff['changed']);
        $this->assertSame(['before' => 500, 'after' => 200, 'changed' => true], $diff['status']);
        $this->assertSame(['x-new' => '2'], $diff['headers']['added']);
        $this->assertSame(['x-old' => '1'], $diff['headers']['removed']);
        $this->assertSame(['content-type' => ['application/json', 'application/json; charset=utf-8']], $diff['headers']['changed']);
        $this->assertArrayNotHasKey('date', $diff['headers']['changed'], 'volatile headers are ignored');

        $ops = array_map(fn ($l) => $l['op'].$l['text'], $diff['body']['lines']);
        $this->assertContains('-    "error": "boom",', $ops, 'JSON is pretty-printed before the line diff');
        $this->assertContains('+    "ok": true', $ops);
        $this->assertContains('+    "id": 7,', $ops);
        $this->assertContains(' {', $ops, 'unchanged lines are kept');

        $same = ResponseDiff::compare(['status' => 200, 'body' => 'x'], ['status' => 200, 'body' => 'x']);
        $this->assertFalse($same['changed']);
        $this->assertSame([], $same['body']['lines']);
    }

    public function test_line_diff_is_minimal(): void
    {
        $body = ResponseDiff::body("a\nb\nc\nd", "a\nc\nd\ne");
        $this->assertSame([' a', '-b', ' c', ' d', '+e'], array_map(fn ($l) => $l['op'].$l['text'], $body['lines']));
    }

    public function test_redaction_helpers(): void
    {
        [$headers, $removed] = ReplayService::redactHeaders(
            ['Authorization' => 'Bearer x', 'X-Trace' => ['a', 'b'], 'Cookie' => 'c=1', 'Accept' => 'json'],
            ['authorization', 'cookie'],
        );
        $this->assertSame(['accept' => 'json', 'x-trace' => 'a, b'], $headers);
        $this->assertSame(['authorization', 'cookie'], $removed);

        $this->assertSame('page=1&token=REDACTED&Api_Key=REDACTED&flag', ReplayService::redactQuery('page=1&token=abc&Api_Key=x&flag', ['token', 'api_key']));
        $this->assertSame('', ReplayService::redactQuery('', ['token']));

        $this->assertSame(['hello', false, false], ReplayService::limitBody('hello', false, 10));
        $this->assertSame(['hel', false, true], ReplayService::limitBody('hello', false, 3));
        $this->assertSame([base64_encode("\xff\xfe"), true, false], ReplayService::limitBody(base64_encode("\xff\xfe"), true, 10));
        $this->assertSame(['ok', false, false], ReplayService::limitBody(base64_encode('ok'), true, 10), 'UTF-8 bodies are stored as text');
        $this->assertSame(['', false, true], ReplayService::limitBody('%%%not-base64', true, 10));
    }

    public function test_replay_keys(): void
    {
        $this->assertSame([null, '01JPROJECT0000000000000000'], ReplayTokens::parseKey('_.01JPROJECT0000000000000000'));
        $this->assertSame(['9a1c7d2e-1111-4a5b-8c6d-0123456789ab', '01JPROJECT0000000000000000'], ReplayTokens::parseKey('9a1c7d2e-1111-4a5b-8c6d-0123456789ab.01JPROJECT0000000000000000'));
        $this->assertNull(ReplayTokens::parseKey('../etc.passwd'));
        $this->assertNull(ReplayTokens::parseKey('_.short'));
    }

    public function test_ingest_url_from_the_agent_url(): void
    {
        $this->assertSame('http://runr-gateway.runr.svc.cluster.local', IngestUrl::httpBase('ws://runr-gateway.runr.svc.cluster.local/ws/agent/01J'));
        $this->assertSame('https://console.example.com/runr', IngestUrl::httpBase('wss://console.example.com/runr/ws/agent/01J'));
        $this->assertNull(IngestUrl::httpBase('not a url'));
    }

    public function test_resource_attributes_are_percent_encoded(): void
    {
        $this->assertSame('runr.project=a%2Cb,runr.tenant=t%3D1', ObservabilityPodSpec::resourceAttributes(['runr.project' => 'a,b', 'runr.tenant' => 't=1']));
    }

    public function test_outbound_url_guard(): void
    {
        $dns = new class extends DnsResolver
        {
            public function addresses(string $name): array
            {
                return ['hooks.example.com' => ['93.184.216.34'], 'internal.example.com' => ['10.1.2.3'], 'mixed.example.com' => ['93.184.216.35', '127.0.0.1']][$name] ?? [];
            }
        };
        $guard = new OutboundUrlGuard($dns);

        $this->assertNull($guard->check('https://hooks.example.com/services/T/B/x'));
        $this->assertNull($guard->check('http://151.101.1.69/hook'));
        $this->assertNotNull($guard->check('http://hooks.example.com/x', false, true), 'https required');
        $this->assertNotNull($guard->check('https://internal.example.com/'));
        $this->assertNotNull($guard->check('https://mixed.example.com/'), 'every address must be public');
        $this->assertNotNull($guard->check('https://169.254.169.254/latest/meta-data'));
        $this->assertNotNull($guard->check('https://[::1]/'));
        $this->assertNotNull($guard->check('https://100.64.1.1/'));
        $this->assertNotNull($guard->check('https://user:pass@hooks.example.com/'));
        $this->assertNotNull($guard->check('https://prometheus.observability.svc:9090/'));
        $this->assertNotNull($guard->check('https://unknown.example.com/'), 'unresolvable');
        $this->assertNotNull($guard->check('ftp://hooks.example.com/'));
        $this->assertNull($guard->check('https://internal.example.com/', true), 'private targets when allowed');

        $this->assertTrue(OutboundUrlGuard::isPublicIp('8.8.8.8'));
        $this->assertFalse(OutboundUrlGuard::isPublicIp('192.168.1.1'));
        $this->assertFalse(OutboundUrlGuard::isPublicIp('fd00::1'));
        $this->assertFalse(OutboundUrlGuard::isPublicIp('::ffff:127.0.0.1'));
    }

    public function test_expected_status_and_body_matching(): void
    {
        $this->assertTrue(UptimeService::statusMatches('', 204));
        $this->assertFalse(UptimeService::statusMatches('', 500));
        $this->assertTrue(UptimeService::statusMatches('200,204', 204));
        $this->assertTrue(UptimeService::statusMatches('200 - 299', 250));
        $this->assertTrue(UptimeService::statusMatches('3xx', 302));
        $this->assertFalse(UptimeService::statusMatches('2xx', 302));

        $this->assertTrue(UptimeService::validExpectedStatus('200-299,301,4xx'));
        $this->assertFalse(UptimeService::validExpectedStatus('ok'));

        $this->assertTrue(UptimeService::bodyMatches('"status":\s*"ok"', '{"status": "ok"}'));
        $this->assertFalse(UptimeService::bodyMatches('maintenance', 'all good'));
        $this->assertNull(UptimeService::bodyMatches('(', 'x'), 'invalid pattern');
        $this->assertTrue(UptimeService::bodyMatches('a~b', 'a~b'), 'the delimiter is escaped');
    }

    public function test_location_aggregation(): void
    {
        $up = ['status' => UptimeCheck::STATUS_UP];
        $down = ['status' => UptimeCheck::STATUS_DOWN];
        $unknown = ['status' => UptimeCheck::STATUS_UNKNOWN];

        $this->assertSame('down', UptimeService::aggregate(['eu' => $up, 'us' => $down], 'up'));
        $this->assertSame('up', UptimeService::aggregate(['eu' => $up, 'us' => $up], 'down'));
        $this->assertSame('unknown', UptimeService::aggregate(['eu' => $up, 'us' => $unknown], 'unknown'));
        $this->assertSame('down', UptimeService::aggregate(['eu' => $up, 'us' => $unknown], 'down'), 'stays down until every location is up');
    }
}
