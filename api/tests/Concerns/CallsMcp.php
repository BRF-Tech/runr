<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Talks to the RunR MCP server (POST /mcp, docs/mcp.md) the way an MCP client does: a bearer API
 * token, initialize → Mcp-Session-Id, then JSON-RPC requests in that session.
 */
trait CallsMcp
{
    private int $mcpNextId = 1;

    /** A plain API token of $user carrying $abilities ('*' = everything the user may do). */
    protected function mcpToken(User $user, array $abilities = ['*'], string $name = 'mcp-test'): string
    {
        return $user->createToken($name, $abilities)->plainTextToken;
    }

    /** POST /mcp with a JSON-RPC message (or batch, or raw string). */
    protected function mcpPost(string $token, array|string $payload, ?string $session = null, array $headers = [], string $accept = 'application/json'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $server = $this->transformHeadersToServerVars(array_merge([
            'Authorization' => 'Bearer '.$token,
            'Accept' => $accept,
            'Content-Type' => 'application/json',
        ], $session ? ['Mcp-Session-Id' => $session] : [], $headers));

        $body = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/mcp', [], [], [], $server, $body);
    }

    /** initialize + notifications/initialized; returns the session id. */
    protected function mcpInitialize(string $token, string $version = '2025-11-25', array $capabilities = []): string
    {
        $response = $this->mcpPost($token, [
            'jsonrpc' => '2.0',
            'id' => $this->mcpNextId++,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => $version,
                'capabilities' => (object) $capabilities,
                'clientInfo' => ['name' => 'runr-tests', 'version' => '1.0.0'],
            ],
        ]);
        $response->assertOk();
        $session = (string) $response->headers->get('Mcp-Session-Id');
        $this->assertNotSame('', $session, 'initialize answers with an Mcp-Session-Id');

        $this->mcpPost($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $session)->assertStatus(202);

        return $session;
    }

    /** One JSON-RPC request in a session; the decoded response object. */
    protected function mcpRpc(string $token, string $session, string $method, array $params = []): array
    {
        $payload = ['jsonrpc' => '2.0', 'id' => $this->mcpNextId++, 'method' => $method];
        if ($params !== []) {
            $payload['params'] = $params;
        }

        $response = $this->mcpPost($token, $payload, $session);
        $response->assertOk();

        return (array) $response->json();
    }

    /** tools/call; the CallToolResult (content, structuredContent, isError). */
    protected function mcpTool(string $token, string $session, string $name, array $arguments = []): array
    {
        $response = $this->mcpRpc($token, $session, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
        $this->assertArrayHasKey('result', $response, 'tools/call answered with an error: '.json_encode($response));

        return (array) $response['result'];
    }

    /** @return list<string> names of the tools tools/list shows */
    protected function mcpToolNames(string $token, string $session): array
    {
        $response = $this->mcpRpc($token, $session, 'tools/list');

        return array_column((array) ($response['result']['tools'] ?? []), 'name');
    }

    /** JSON-RPC messages of an SSE body (data: lines of each event). */
    protected function mcpSseMessages(TestResponse $response): array
    {
        $body = $response->streamedContent();
        $messages = [];
        foreach (preg_split("/\r?\n\r?\n/", $body) as $event) {
            $data = [];
            foreach (preg_split("/\r?\n/", $event) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5));
                }
            }
            if ($data) {
                $decoded = json_decode(implode("\n", $data), true);
                if (is_array($decoded)) {
                    $messages[] = $decoded;
                }
            }
        }

        return $messages;
    }

    /** The error object of a failed tool result. */
    protected function mcpToolError(array $result): array
    {
        $this->assertTrue($result['isError'] ?? false, 'expected a tool error: '.json_encode($result));

        return (array) ($result['structuredContent']['error'] ?? []);
    }
}
