<?php

namespace App\Mcp\Server;

use App\Mcp\Prompts\PromptRegistry;
use App\Mcp\Protocol\JsonRpc;
use App\Mcp\Protocol\McpError;
use App\Mcp\Protocol\ProtocolVersion;
use App\Mcp\Resources\ResourceRegistry;
use App\Mcp\Support\McpAudit;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolRegistry;
use App\Mcp\Tools\ToolResult;
use App\Models\AuditEvent;
use App\Services\Runtimes\JsonSchemaValidator;
use App\Services\WhiteLabel\BrandingService;
use App\Support\TenantAccess;
use Illuminate\Support\Facades\Cache;

/**
 * The RunR MCP server: JSON-RPC methods of the Model Context Protocol (docs/mcp.md).
 *
 *   initialize, ping, tools/list, tools/call, resources/list, resources/templates/list,
 *   resources/read, prompts/list, prompts/get, completion/complete, logging/setLevel
 *   notifications/initialized, notifications/cancelled (others are accepted and ignored)
 *
 * The transport (sessions, JSON vs SSE answers, auth) is McpEndpointController's.
 */
final class McpServer
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ResourceRegistry $resources,
        private readonly PromptRegistry $prompts,
        private readonly Completions $completions,
        private readonly SessionStore $sessions,
        private readonly McpAudit $audit,
    ) {}

    /** Answers one JSON-RPC request with its response message (result or error). */
    public function handleRequest(array $message, McpExchange $exchange): array
    {
        $id = $message['id'];
        $params = $message['params'] ?? [];

        try {
            if (! is_array($params)) {
                throw McpError::invalidParams('params must be an object');
            }

            $result = match ($message['method']) {
                'initialize' => $this->initialize($params, $exchange),
                'ping' => [],
                'tools/list' => $this->listTools($exchange),
                'tools/call' => $this->callTool($params, $id, $exchange),
                'resources/list' => ['resources' => $this->resources->list($this->context($exchange, 'resources/list'))],
                'resources/templates/list' => ['resourceTemplates' => $this->resources->templates()],
                'resources/read' => $this->readResource($params, $exchange),
                'prompts/list' => ['prompts' => $this->prompts->list()],
                'prompts/get' => $this->getPrompt($params),
                'completion/complete' => $this->complete($params, $exchange),
                'logging/setLevel' => $this->setLogLevel($params, $exchange),
                default => throw McpError::methodNotFound($message['method']),
            };

            return JsonRpc::result($id, $result);
        } catch (McpError $e) {
            return JsonRpc::fromException($id, $e);
        } catch (\Throwable $e) {
            report($e);

            return JsonRpc::error($id, McpError::INTERNAL_ERROR, 'Internal error');
        }
    }

    /** A notification from the client: nothing is answered. */
    public function handleNotification(array $message, McpExchange $exchange): void
    {
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        switch ($message['method']) {
            case 'notifications/initialized':
                if ($exchange->session) {
                    $this->sessions->markInitialized($exchange->session);
                }
                break;

            case 'notifications/cancelled':
                // The running request (another HTTP request of the session) polls this flag
                $requestId = $params['requestId'] ?? null;
                if ($exchange->session && (is_string($requestId) || is_int($requestId))) {
                    try {
                        Cache::put(ToolContext::cancelKey($exchange->session->id, $requestId), true, now()->addMinutes(15));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
                break;
        }
    }

    /** Whether a request should be answered as an SSE stream (long-running tools). */
    public function streams(array $message, McpExchange $exchange): bool
    {
        if (($message['method'] ?? null) !== 'tools/call') {
            return false;
        }

        $tool = $this->tools->find((string) ($message['params']['name'] ?? ''));

        return $tool !== null && $tool->streams() && $this->tools->permits($tool, $exchange->user);
    }

    // ── initialize ──────────────────────────────────────────────────────────

    private function initialize(array $params, McpExchange $exchange): array
    {
        $version = ProtocolVersion::negotiate($params['protocolVersion'] ?? null);
        $clientInfo = is_array($params['clientInfo'] ?? null) ? $params['clientInfo'] : [];
        $capabilities = is_array($params['capabilities'] ?? null) ? $params['capabilities'] : [];

        [$plain, $session] = $this->sessions->create($exchange->user, $exchange->tokenId(), $version, $clientInfo, $capabilities);
        $exchange->session = $session;
        $exchange->newSessionId = $plain;
        $exchange->protocolVersion = $version;

        $this->audit->sessionStarted($exchange->request, $exchange->user, $clientInfo, $version);

        $serverInfo = ['name' => 'runr', 'version' => (string) config('app.version', 'dev')];
        if ($version >= '2025-06-18') {
            $serverInfo['title'] = self::title();
        }

        return [
            'protocolVersion' => $version,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
                'prompts' => ['listChanged' => false],
                'logging' => new \stdClass,
                'completions' => new \stdClass,
            ],
            'serverInfo' => $serverInfo,
            'instructions' => $this->instructions(),
        ];
    }

    /** serverInfo.title: config mcp.server_title, else the organization's brand name, else RunR. */
    public static function title(): string
    {
        $configured = trim((string) config('mcp.server_title', ''));
        if ($configured !== '') {
            return $configured;
        }

        try {
            return (string) (app(BrandingService::class)->forTenant(TenantAccess::currentId())['product_name'] ?? 'RunR');
        } catch (\Throwable) {
            return 'RunR';
        }
    }

    private function instructions(): string
    {
        return implode("\n", [
            self::title().' is a serverless platform on Kubernetes and Knative: projects are functions (xaas: one file, faas: several files with dependencies) or container images (caas), deployed to regions with scale-to-zero.',
            'Start with whoami (permissions of this connection) and projects_list. Code changes create versions (code_write_files); deploy rolls a version out and waits for it; logs_tail and deployment_status explain failures.',
            'Destructive tools ask for a confirmation argument: ask the user before setting it. Secret values are never shown except by secret_reveal.',
            'Documentation pages are resources: runr://docs/{page} (e.g. runtimes, workflows, bindings, mcp).',
        ]);
    }

    // ── tools ───────────────────────────────────────────────────────────────

    private function listTools(McpExchange $exchange): array
    {
        return ['tools' => array_values(array_map(
            fn (Tool $tool) => $tool->definition($exchange->protocolVersion),
            $this->tools->visibleFor($exchange->user),
        ))];
    }

    private function callTool(array $params, string|int $requestId, McpExchange $exchange): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (! is_string($name) || $name === '') {
            throw McpError::invalidParams('params.name is required');
        }
        if (! is_array($arguments)) {
            throw McpError::invalidParams('params.arguments must be an object');
        }

        $tool = $this->tools->find($name);
        if (! $tool || ! $this->tools->permits($tool, $exchange->user)) {
            // A tool the token's permissions do not cover does not exist for this caller
            $this->audit->toolCall($exchange->request, $exchange->user, $name, array_keys($arguments), AuditEvent::RESULT_DENIED, 0, null, $tool ? 'not permitted' : 'unknown tool', $exchange->clientName());

            throw McpError::invalidParams("Unknown tool: {$name}");
        }

        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $token = $meta['progressToken'] ?? null;
        $context = new ToolContext(
            $exchange->request,
            $exchange->user,
            $tool->name(),
            $exchange->protocolVersion,
            $exchange->sink,
            is_string($token) || is_int($token) ? $token : null,
            $exchange->session?->id,
            $requestId,
            $exchange->logLevel(),
        );

        $started = hrtime(true);
        $result = $this->runTool($tool, $arguments, $context);
        $ms = (int) round((hrtime(true) - $started) / 1e6);

        $this->audit->toolCall(
            $exchange->request,
            $exchange->user,
            $tool->name(),
            array_keys($arguments),
            $result->isError ? AuditEvent::RESULT_FAILURE : AuditEvent::RESULT_SUCCESS,
            $ms,
            $context->target(),
            $result->isError ? $result->text : null,
            $exchange->clientName(),
        );

        return $result->toArray($exchange->protocolVersion);
    }

    private function runTool(Tool $tool, array $arguments, ToolContext $context): ToolResult
    {
        $problems = (new JsonSchemaValidator(Schema::normalize($tool->inputSchema())))->validate($arguments);
        if ($problems) {
            $lines = array_map(fn (array $p) => "{$p['path']}: {$p['message']}", $problems);

            return ToolResult::error("Invalid arguments for {$tool->name()}:\n- ".implode("\n- ", $lines), ['problems' => $problems]);
        }

        try {
            return $tool->handle($arguments, $context);
        } catch (ToolError $e) {
            return $e->toResult();
        } catch (\Throwable $e) {
            report($e);

            return ToolResult::error('The tool failed unexpectedly'.(config('app.debug') ? ': '.$e->getMessage() : '.'));
        }
    }

    // ── resources, prompts, completion, logging ─────────────────────────────

    private function readResource(array $params, McpExchange $exchange): array
    {
        $uri = $params['uri'] ?? null;
        if (! is_string($uri) || $uri === '') {
            throw McpError::invalidParams('params.uri is required');
        }

        return ['contents' => $this->resources->read($uri, $this->context($exchange, 'resources/read'))];
    }

    private function getPrompt(array $params): array
    {
        $name = $params['name'] ?? null;
        if (! is_string($name) || $name === '') {
            throw McpError::invalidParams('params.name is required');
        }

        return $this->prompts->get($name, is_array($params['arguments'] ?? null) ? $params['arguments'] : []);
    }

    private function complete(array $params, McpExchange $exchange): array
    {
        $ref = is_array($params['ref'] ?? null) ? $params['ref'] : [];
        $argument = is_array($params['argument'] ?? null) ? $params['argument'] : [];
        if (! isset($ref['type'], $argument['name'])) {
            throw McpError::invalidParams('params.ref and params.argument are required');
        }

        return ['completion' => $this->completions->complete(
            $ref,
            (string) $argument['name'],
            (string) ($argument['value'] ?? ''),
            $this->context($exchange, 'completion/complete'),
        )];
    }

    private function setLogLevel(array $params, McpExchange $exchange): array
    {
        $level = $params['level'] ?? null;
        if (! is_string($level) || ! in_array($level, ToolContext::LOG_LEVELS, true)) {
            throw McpError::invalidParams('params.level must be one of: '.implode(', ', ToolContext::LOG_LEVELS));
        }
        if ($exchange->session) {
            $this->sessions->setLogLevel($exchange->session, $level);
        }

        return [];
    }

    private function context(McpExchange $exchange, string $name): ToolContext
    {
        return new ToolContext($exchange->request, $exchange->user, $name, $exchange->protocolVersion);
    }
}
