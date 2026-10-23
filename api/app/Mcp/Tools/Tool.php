<?php

namespace App\Mcp\Tools;

/**
 * One MCP tool (docs/mcp.md, "Tools"). A tool is a thin wrapper over RunR API routes: it calls
 * them through $context->api() and shapes a concise answer. What it may do is decided by the
 * routes (route authorization, policies, residency, quotas); permissions() only decides whether
 * the caller sees the tool at all (tools/list) — a tool whose permissions the token does not
 * hold is neither listed nor callable.
 */
abstract class Tool
{
    /** Unique name, snake_case with the area first: project_create, deployment_status. */
    abstract public function name(): string;

    /** Human-readable name (Tool.title, protocol 2025-06-18+). */
    abstract public function title(): string;

    /** What the tool does and when to use it, for the model. */
    abstract public function description(): string;

    /** JSON Schema of the arguments (Schema::object(...)). */
    abstract public function inputSchema(): array;

    /**
     * Permissions the caller must hold (organization-wide or on at least one project) for the
     * tool to be listed: every entry is required; an entry "a|b" is satisfied by either; an entry
     * "@platform:a" needs a platform administrator whose token carries a (the rule of API routes
     * marked so in config/route_permissions.php). An empty list: every signed-in caller.
     *
     * @return list<string>
     */
    abstract public function permissions(): array;

    /** Catalog group (settings page, docs): projects, code, deployments, operations, … */
    abstract public function group(): string;

    /** @throws ToolError */
    abstract public function handle(array $arguments, ToolContext $context): ToolResult;

    /** Tool annotations (hints, not guarantees). */
    public function annotations(): array
    {
        return [
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ];
    }

    /** Long-running: answered as an SSE stream with progress notifications when the client accepts one. */
    public function streams(): bool
    {
        return false;
    }

    /** Annotations of a read-only tool. */
    protected static function readOnly(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    /** Annotations of a tool that changes something without removing anything. */
    protected static function additive(bool $idempotent = false): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => $idempotent, 'openWorldHint' => false];
    }

    /** Annotations of a tool that removes or replaces something. */
    protected static function destructive(bool $idempotent = true): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => $idempotent, 'openWorldHint' => false];
    }

    /** The tool definition of tools/list. */
    public function definition(string $protocolVersion): array
    {
        $definition = [
            'name' => $this->name(),
            'description' => $this->description(),
            'inputSchema' => $this->inputSchema(),
        ];
        if ($protocolVersion >= '2025-06-18') {
            $definition['title'] = $this->title();
        }
        $definition['annotations'] = ['title' => $this->title()] + $this->annotations();

        return $definition;
    }
}
