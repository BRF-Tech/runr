<?php

namespace App\Mcp\Tools\Catalog\Sandboxes;

use App\Mcp\Tools\Concerns\UsesSandboxes;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class SandboxNetwork extends Tool
{
    use UsesSandboxes;

    public function name(): string
    {
        return 'sandbox_network';
    }

    public function title(): string
    {
        return 'Set the outbound network of a sandbox';
    }

    public function description(): string
    {
        return 'Replaces the outbound network of a running sandbox: egress_allow lists the addresses, CIDRs or host names (each optionally with a port) it may reach — e.g. pypi.org and files.pythonhosted.org:443 to install packages — and an empty list cuts it off again. Host names are resolved now; cloud metadata addresses are never reachable; inbound traffic is always denied.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'sandbox' => self::sandboxArgument(),
            'egress_allow' => Schema::array(Schema::string('Address, CIDR or host name, optionally with a port.', ['minLength' => 1, 'maxLength' => 255]), 'What the sandbox may reach; [] = no network.', 50),
        ], ['sandbox', 'egress_allow']);
    }

    public function permissions(): array
    {
        return ['sandboxes.create'];
    }

    public function group(): string
    {
        return 'sandboxes';
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = (string) $arguments['sandbox'];
        $allow = array_values((array) $arguments['egress_allow']);
        $context->setTarget('sandbox', $id);

        $sandbox = self::sandboxSummary((array) $this->sandboxCall($context, 'PUT', self::sandboxPath($id, 'network'), [
            'network' => $allow === [] ? 'none' : ['egress_allow' => $allow],
        ], [], 'The network was not changed')->data());

        return ToolResult::ok(['sandbox' => $sandbox], $allow === [] ? 'The sandbox has no network.' : 'The sandbox may reach: '.implode(', ', $allow).'.');
    }
}
