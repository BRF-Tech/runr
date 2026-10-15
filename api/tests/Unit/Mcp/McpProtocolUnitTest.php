<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Protocol\JsonRpc;
use App\Mcp\Protocol\McpError;
use App\Mcp\Protocol\ProtocolVersion;
use App\Mcp\Server\SseSink;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolResult;
use Tests\TestCase;

/**
 * Building blocks of the MCP transport: JSON-RPC classification, version negotiation, tool
 * results per protocol revision, the SSE framing and the schema helpers.
 */
class McpProtocolUnitTest extends TestCase
{
    public function test_messages_are_classified(): void
    {
        $this->assertSame(JsonRpc::REQUEST, JsonRpc::kind(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
        $this->assertSame(JsonRpc::REQUEST, JsonRpc::kind(['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'tools/list']));
        $this->assertSame(JsonRpc::NOTIFICATION, JsonRpc::kind(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        $this->assertSame(JsonRpc::RESPONSE, JsonRpc::kind(['jsonrpc' => '2.0', 'id' => 3, 'result' => []]));
        $this->assertSame(JsonRpc::RESPONSE, JsonRpc::kind(['jsonrpc' => '2.0', 'id' => 3, 'error' => ['code' => 1]]));

        $this->assertSame(JsonRpc::INVALID, JsonRpc::kind(['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping']));
        $this->assertSame(JsonRpc::INVALID, JsonRpc::kind(['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping']), 'MCP ids are never null');
        $this->assertSame(JsonRpc::INVALID, JsonRpc::kind(['jsonrpc' => '2.0', 'id' => 1.5, 'method' => 'ping']));
        $this->assertSame(JsonRpc::INVALID, JsonRpc::kind(['jsonrpc' => '2.0', 'method' => '']));
        $this->assertSame(JsonRpc::INVALID, JsonRpc::kind(['jsonrpc' => '2.0', 'id' => 1]));
        $this->assertSame(JsonRpc::INVALID, JsonRpc::kind('ping'));
        $this->assertSame(JsonRpc::INVALID, JsonRpc::kind([1, 2]));
    }

    public function test_results_and_errors_are_json_rpc(): void
    {
        $this->assertSame('{"jsonrpc":"2.0","id":7,"result":{}}', JsonRpc::encode(JsonRpc::result(7, [])));
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 'x', 'error' => ['code' => -32601, 'message' => 'Method not found: nope']],
            JsonRpc::fromException('x', McpError::methodNotFound('nope')),
        );
        $this->assertSame(-32002, McpError::resourceNotFound('runr://x')->toError()['code']);
        $this->assertSame(['uri' => 'runr://x'], McpError::resourceNotFound('runr://x')->toError()['data']);
    }

    public function test_versions_are_negotiated(): void
    {
        $this->assertSame('2025-11-25', ProtocolVersion::latest());
        $this->assertSame('2025-06-18', ProtocolVersion::negotiate('2025-06-18'));
        $this->assertSame('2025-03-26', ProtocolVersion::negotiate('2025-03-26'));
        $this->assertSame('2025-11-25', ProtocolVersion::negotiate('2024-11-05'), 'an unknown version gets the newest');
        $this->assertSame('2025-11-25', ProtocolVersion::negotiate(null));

        $this->assertTrue(ProtocolVersion::allowsBatches('2025-03-26'));
        $this->assertFalse(ProtocolVersion::allowsBatches('2025-06-18'));
        $this->assertFalse(ProtocolVersion::allowsBatches('2025-11-25'));
    }

    public function test_tool_results_follow_the_protocol_revision(): void
    {
        $ok = ToolResult::ok(['a' => 1], 'Done.');

        $new = $ok->toArray('2025-11-25');
        $this->assertSame(['a' => 1], $new['structuredContent']);
        $this->assertStringStartsWith("Done.\n\n", $new['content'][0]['text']);
        $this->assertArrayNotHasKey('isError', $new);

        $old = $ok->toArray('2025-03-26');
        $this->assertArrayNotHasKey('structuredContent', $old, 'structuredContent exists from 2025-06-18 on');
        $this->assertSame('text', $old['content'][0]['type']);

        $error = ToolResult::error('Nope', ['status' => 403])->toArray('2025-06-18');
        $this->assertTrue($error['isError']);
        $this->assertSame(['message' => 'Nope', 'status' => 403], $error['structuredContent']['error']);
    }

    public function test_structured_content_is_always_an_object(): void
    {
        $this->assertSame(['items' => [1, 2]], ToolResult::ok([1, 2])->structured);
        $this->assertInstanceOf(\stdClass::class, ToolResult::ok([])->structured);
        $this->assertSame('{}', json_encode(ToolResult::ok([])->toArray('2025-11-25')['structuredContent']));
    }

    public function test_sse_events_carry_one_json_rpc_message(): void
    {
        $written = '';
        $sink = new SseSink('s1', function (string $chunk) use (&$written) {
            $written .= $chunk;
        });

        $sink->notify('notifications/progress', ['progressToken' => 't', 'progress' => 1]);
        $sink->send(JsonRpc::result(5, ['ok' => true]));

        $this->assertTrue($sink->streaming());
        $this->assertSame(
            "id: s1-1\nevent: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\",\"params\":{\"progressToken\":\"t\",\"progress\":1}}\n\n"
            ."id: s1-2\nevent: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":5,\"result\":{\"ok\":true}}\n\n",
            $written,
        );
    }

    public function test_schema_helpers_produce_json_objects(): void
    {
        $empty = Schema::object();
        $this->assertSame('{"type":"object","properties":{},"additionalProperties":false}', json_encode($empty));
        $this->assertSame(['type' => 'object', 'properties' => [], 'additionalProperties' => false], Schema::normalize($empty));

        $schema = Schema::object(['name' => Schema::string('A name', ['maxLength' => 5])], ['name']);
        $this->assertSame(['name'], $schema['required']);
        $this->assertSame(true, Schema::confirm('the deletion')['const']);
        $this->assertSame('integer', Schema::enum([1, 2], 'n')['type']);
    }
}
