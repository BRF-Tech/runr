<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Hosting\CallRecorder;
use App\Mcp\Hosting\GatewayIdentity;
use App\Mcp\Models\HostedServerCall;
use App\Mcp\OAuth\Pkce;
use App\Mcp\OAuth\RedirectUris;
use App\Mcp\Support\CurrentDeployment;
use App\Mcp\Support\LogLines;
use App\Mcp\Support\McpUrls;
use App\Workflows\Engine\Signature;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Pure helpers of the MCP surface: PKCE, redirect URI rules, the gateway signature (the same
 * construction the starters verify), call log parsing, log lines and URLs.
 */
class McpSupportUnitTest extends TestCase
{
    public function test_pkce_s256_matches_rfc_7636(): void
    {
        // RFC 7636 appendix B
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $this->assertSame($challenge, Pkce::challenge($verifier));
        $this->assertTrue(Pkce::verify($verifier, $challenge));
        $this->assertFalse(Pkce::verify($verifier.'x', $challenge));
        $this->assertFalse(Pkce::verify('short', Pkce::challenge('short')), 'verifiers have 43–128 characters');
        $this->assertSame(1, preg_match(Pkce::CHALLENGE_PATTERN, $challenge));
    }

    public function test_redirect_uris_follow_oauth_2_1_and_native_app_rules(): void
    {
        foreach ([
            'https://client.example.com/callback',
            'http://localhost:33418/callback',
            'http://127.0.0.1:8765/cb',
            'http://[::1]:9000/cb',
            'cursor://anysphere.cursor-retrieval/oauth/callback',
            'com.example.app:/oauth2redirect',
        ] as $ok) {
            $this->assertTrue(RedirectUris::valid($ok), $ok);
        }

        foreach ([
            'http://client.example.com/callback',  // http only on loopback
            'https://client.example.com/cb#frag',  // no fragments
            'javascript:alert(1)',
            'data:text/html,hi',
            'file:///etc/passwd',
            'https://user:pw@client.example.com/cb',
            'https:///nohost',
            '',
            'not a uri',
        ] as $bad) {
            $this->assertFalse(RedirectUris::valid($bad), $bad);
        }

        config(['mcp.oauth.allow_http_loopback_redirects' => false]);
        $this->assertFalse(RedirectUris::valid('http://localhost:3000/cb'));

        $this->assertSame('https://c.test/cb?x=1&code=abc&state=s%20t', RedirectUris::with('https://c.test/cb?x=1', ['code' => 'abc', 'state' => 's t', 'iss' => null]));
    }

    public function test_the_gateway_signature_is_reproducible_by_a_server(): void
    {
        $identity = (new GatewayIdentity(GatewayIdentity::AUTH_PUBLIC, null, null))->header();
        $decoded = json_decode(base64_decode(strtr($identity, '-_', '+/')), true);
        $this->assertSame('public', $decoded['auth']);
        $this->assertNull($decoded['user']);
        $this->assertSame([], $decoded['scopes']);

        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';
        $header = GatewayIdentity::sign(['rmg_key'], 'POST', '/mcp', $body, $identity, 1_700_000_000);
        $canonical = "POST\n/mcp\n".hash('sha256', $body)."\n".$identity;

        // What examples/mcp/*/server.* compute
        $this->assertSame('t=1700000000,v1='.hash_hmac('sha256', '1700000000.'.$canonical, 'rmg_key'), $header);
        $this->assertTrue(Signature::verify($canonical, $header, ['rmg_key'], 300, 1_700_000_100));
        $this->assertFalse(Signature::verify($canonical, $header, ['other'], 300, 1_700_000_100));
        $this->assertFalse(Signature::verify($canonical, $header, ['rmg_key'], 300, 1_700_001_000), 'stale signatures are refused');

        // During a key rotation both keys sign
        $rotation = GatewayIdentity::sign(['new', 'old'], 'POST', '/mcp', $body, $identity, 1_700_000_000);
        $this->assertSame(2, substr_count($rotation, 'v1='));
        $this->assertTrue(Signature::verify($canonical, $rotation, ['old'], 300, 1_700_000_000));
    }

    public function test_calls_are_described_without_their_arguments(): void
    {
        $this->assertSame(
            ['method' => 'tools/call', 'name' => 'get_weather', 'id' => '7'],
            CallRecorder::describe('{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"get_weather","arguments":{"city":"Ankara"}}}'),
        );
        $this->assertSame('runr://x', CallRecorder::describe('{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"runr://x"}}')['name']);
        $this->assertSame('notifications/initialized', CallRecorder::describe('{"jsonrpc":"2.0","method":"notifications/initialized"}')['method']);
        $this->assertSame('batch', CallRecorder::describe('[{"jsonrpc":"2.0","method":"a"},{"jsonrpc":"2.0","method":"b"}]')['method']);
        $this->assertSame('invalid', CallRecorder::describe('nope')['method']);
        $this->assertSame('stream/open', CallRecorder::describe('', 'GET')['method']);
        $this->assertSame('session/delete', CallRecorder::describe('', 'DELETE')['method']);
    }

    public function test_outcomes_read_json_and_sse_answers(): void
    {
        $this->assertSame([HostedServerCall::OK, null], CallRecorder::outcome(200, '{"jsonrpc":"2.0","id":1,"result":{"content":[]}}'));
        $this->assertSame([HostedServerCall::ERROR, 'Unknown tool'], CallRecorder::outcome(200, '{"jsonrpc":"2.0","id":1,"error":{"code":-32602,"message":"Unknown tool"}}'));
        $this->assertSame([HostedServerCall::ERROR, 'city missing'], CallRecorder::outcome(200, '{"jsonrpc":"2.0","id":1,"result":{"isError":true,"content":[{"type":"text","text":"city missing"}]}}'));
        $this->assertSame(HostedServerCall::DENIED, CallRecorder::outcome(401, '')[0]);
        $this->assertSame(HostedServerCall::ERROR, CallRecorder::outcome(500, '')[0]);

        $sse = "event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\",\"params\":{}}\n\n"
             ."event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":2,\"error\":{\"code\":-1,\"message\":\"boom\"}}\n\n";
        $this->assertSame([HostedServerCall::ERROR, 'boom'], CallRecorder::outcome(200, $sse));
        $this->assertCount(2, CallRecorder::sseMessages($sse));
    }

    public function test_log_lines_are_parsed_filtered_and_formatted(): void
    {
        $text = "=== shop-00001-abc (Running) ===\n1700000000000\tstarted\n1700000001000\tERROR: db down\n=== mcp-gateway ===\n1700000002000\t[mcp] tools/call x\nplain line\n";
        $lines = LogLines::parse($text);

        $this->assertCount(4, $lines);
        $this->assertSame(['ts' => 1700000000000, 'pod' => 'shop-00001-abc', 'line' => 'started'], $lines[0]);
        $this->assertSame('mcp-gateway', $lines[2]['pod']);
        $this->assertSame(['ts' => null, 'pod' => 'mcp-gateway', 'line' => 'plain line'], $lines[3]);

        $this->assertCount(1, LogLines::filter($lines, errorsOnly: true));
        // Lines without a timestamp (kubectl fallback) are kept
        $this->assertCount(3, LogLines::filter($lines, sinceMs: 1700000001000));
        $this->assertSame('plain line', LogLines::filter($lines, limit: 1)[0]['line']);
        $this->assertCount(1, LogLines::filter($lines, contains: 'DB DOWN'));

        $this->assertSame('2023-11-14T22:13:20.000Z [shop-00001-abc] started', LogLines::format($lines[0]));
        $this->assertSame(1_700_000_000_000 - 15 * 60_000, LogLines::sinceMs('15m', 1_700_000_000_000));
        $this->assertSame(1_700_000_000_000, LogLines::sinceMs('1700000000'));
        $this->assertSame(1_700_000_000_000, LogLines::sinceMs('1700000000000'));
        $this->assertNull(LogLines::sinceMs('not a time at all'));
    }

    public function test_the_current_deployment_is_picked_like_the_cli(): void
    {
        $list = [['id' => 'c', 'status' => 'failed'], ['id' => 'b', 'status' => 'running'], ['id' => 'a', 'status' => 'active']];
        $this->assertSame('a', CurrentDeployment::pick($list)['id']);
        $this->assertSame('b', CurrentDeployment::pick(array_slice($list, 0, 2))['id']);
        $this->assertSame('c', CurrentDeployment::pick([$list[0]])['id']);
        $this->assertNull(CurrentDeployment::pick([]));
    }

    public function test_urls_live_on_the_console_origin(): void
    {
        $request = Request::create('https://runr.example.com/mcp', 'POST');

        $this->assertSame('https://runr.example.com/mcp', McpUrls::endpoint($request));
        $this->assertSame('https://runr.example.com/.well-known/oauth-protected-resource/mcp', McpUrls::resourceMetadata($request));
        $this->assertSame('https://runr.example.com/mcp/servers/weather', McpUrls::hostedServer('weather', $request));
        $this->assertSame('', McpUrls::resourceHandle('https://runr.example.com/mcp/', $request));
        $this->assertSame('weather', McpUrls::resourceHandle('https://runr.example.com/mcp/servers/weather', $request));
        $this->assertNull(McpUrls::resourceHandle('https://evil.example.com/mcp', $request));
        $this->assertNull(McpUrls::resourceHandle('https://runr.example.com/mcp/servers/a/b', $request));

        config(['mcp.public_url' => 'https://console.example.org/']);
        $this->assertSame('https://console.example.org/mcp', McpUrls::endpoint($request));
    }
}
