<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Engine\Protocol;
use App\Workflows\Engine\ProtocolException;
use App\Workflows\Engine\Signature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Wire format of protocol v1: request envelope, op parsing and validation. */
class ProtocolTest extends TestCase
{
    public function test_envelope_embeds_the_request_as_a_signed_string(): void
    {
        $env = Protocol::envelope(['type' => 'execute', 'workflow' => 'orders', 'run' => ['id' => 'r1'], 'steps' => new \stdClass], ['rwk_k'], 1700000000);

        $body = json_decode($env['body'], true);
        $this->assertSame('v1', $body['runr_workflow']);
        $this->assertIsString($body['request']);
        $this->assertSame($env['request'], $body['request']);
        $this->assertSame($body['signature'], $env['headers']['X-RunR-Signature']);
        $this->assertSame('v1', $env['headers']['X-RunR-Workflow-Protocol']);
        $this->assertSame('r1', $env['headers']['X-RunR-Run-Id']);
        $this->assertSame('orders', $env['headers']['X-RunR-Workflow']);

        // The request string keeps {} as an object and verifies against the header
        $this->assertStringContainsString('"steps":{}', $body['request']);
        $this->assertTrue(Signature::verify($body['request'], $body['signature'], ['rwk_k'], 300, 1700000000));
    }

    public function test_parses_ops_and_keeps_objects(): void
    {
        $ops = Protocol::parseOps('{"runr_workflow":"v1","ops":[{"op":"step_completed","id":"a","output":{"empty":{},"list":[]}}]}');

        $this->assertCount(1, $ops);
        $this->assertSame('step_completed', $ops[0]['op']);
        $this->assertSame('{"empty":{},"list":[]}', Protocol::encode($ops[0]['output']));
    }

    public function test_unwraps_a_runtime_wrapper_response(): void
    {
        $ops = Protocol::parseOps('{"statusCode":200,"headers":{},"body":{"runr_workflow":"v1","ops":[{"op":"run_completed","output":1}]}}');
        $this->assertSame('run_completed', $ops[0]['op']);

        $ops = Protocol::parseOps('{"statusCode":200,"body":"{\"runr_workflow\":\"v1\",\"ops\":[{\"op\":\"run_completed\"}]}"}');
        $this->assertSame('run_completed', $ops[0]['op']);
    }

    public function test_normalizes_errors(): void
    {
        $ops = Protocol::parseOps('{"runr_workflow":"v1","ops":[{"op":"step_failed","id":"a","error":"boom"},{"op":"run_failed","error":{"name":"TypeError","message":"x","stack":"at y"}}]}');

        $this->assertSame(['name' => 'Error', 'message' => 'boom'], $ops[0]['error']);
        $this->assertSame(['name' => 'TypeError', 'message' => 'x', 'stack' => 'at y'], $ops[1]['error']);
    }

    public function test_single_send_event_object_becomes_a_list(): void
    {
        $ops = Protocol::parseOps('{"runr_workflow":"v1","ops":[{"op":"send_event","id":"s","events":{"name":"a.b","data":{}}}]}');

        $this->assertIsArray($ops[0]['events']);
        $this->assertCount(1, $ops[0]['events']);
    }

    public static function invalidResponses(): array
    {
        return [
            'not json' => ['<html>502</html>'],
            'not an object' => ['[1,2]'],
            'no version' => ['{"ops":[{"op":"run_completed"}]}'],
            'wrong version' => ['{"runr_workflow":"v9","ops":[{"op":"run_completed"}]}'],
            'no ops' => ['{"runr_workflow":"v1"}'],
            'empty ops' => ['{"runr_workflow":"v1","ops":[]}'],
            'unknown op' => ['{"runr_workflow":"v1","ops":[{"op":"teleport","id":"a"}]}'],
            'step op no id' => ['{"runr_workflow":"v1","ops":[{"op":"step_completed"}]}'],
            'step id too long' => ['{"runr_workflow":"v1","ops":[{"op":"step_completed","id":"'.str_repeat('x', 256).'"}]}'],
            'wait no event' => ['{"runr_workflow":"v1","ops":[{"op":"wait_for_event","id":"w"}]}'],
            'invoke no target' => ['{"runr_workflow":"v1","ops":[{"op":"invoke","id":"i"}]}'],
            'sleep no time' => ['{"runr_workflow":"v1","ops":[{"op":"sleep","id":"s"}]}'],
            'send no events' => ['{"runr_workflow":"v1","ops":[{"op":"send_event","id":"s","events":[]}]}'],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_rejects_invalid_responses(string $body): void
    {
        $this->expectException(ProtocolException::class);
        Protocol::parseOps($body);
    }

    public function test_size(): void
    {
        $this->assertSame(2, Protocol::size(new \stdClass));
        $this->assertSame(4, Protocol::size(null));
        $this->assertSame(strlen('{"a":"é"}'), Protocol::size(['a' => 'é']));
    }
}
