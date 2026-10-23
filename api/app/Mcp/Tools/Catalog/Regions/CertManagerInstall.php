<?php

namespace App\Mcp\Tools\Catalog\Regions;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\UsesCertManager;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class CertManagerInstall extends Tool
{
    use UsesCertManager;

    public function name(): string
    {
        return 'cert_manager_install';
    }

    public function title(): string
    {
        return 'Set up cert-manager in a region';
    }

    public function description(): string
    {
        return 'Sets up cert-manager in a region so custom domains get certificates (TLS mode cert-manager): reuses a cert-manager the cluster already runs, else installs RunR\'s (pinned chart, images from RunR\'s mirror), then creates the default ClusterIssuer — ACME (Let\'s Encrypt) where the region is reachable from the internet and an ACME e-mail is configured, else a region CA — unless the operator named an issuer. Run again it repairs a failed install or a missing issuer. Knative Serving must be installed. Queued; with wait (default true) the tool follows it until ready or error.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'region' => Schema::string('Region id or name.', ['minLength' => 1, 'maxLength' => 64]),
            'wait' => Schema::boolean('Follow the install until it is ready or failed (default true).', true),
            'timeout_seconds' => Schema::integer('Longest wait (default and cap: the server maximum).', 1, 3600),
        ], ['region']);
    }

    public function permissions(): array
    {
        return ['regions.manage'];
    }

    public function group(): string
    {
        return 'regions';
    }

    public function annotations(): array
    {
        // Adds cert-manager and its issuers; nothing that runs is replaced (a reused cert-manager stays as it is)
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function streams(): bool
    {
        return true;
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $regionId = $this->regionId($context, (string) $arguments['region']);
        $context->setTarget('region', $regionId);
        $deadline = time() + $context->maxWait(isset($arguments['timeout_seconds']) ? (int) $arguments['timeout_seconds'] : null);

        $context->progress(0, null, 'Queueing the installation…');
        $queued = $context->api()->post(InternalApi::path('knative-regions', $regionId, 'cert-manager'), []);
        if (! $queued->ok()) {
            throw ToolError::fromApi($queued, 'cert-manager was not set up');
        }

        if (! ($arguments['wait'] ?? true)) {
            return ToolResult::ok(['status' => 'installing'], 'The installation is queued; cert_manager_status follows it.');
        }

        $state = self::certManagerSummary($this->waitForCertManager($context, $regionId, $deadline));

        return match ($state['status'] ?? null) {
            'ready' => ToolResult::ok($state, 'cert-manager is ready'.(isset($state['issuer']) ? " with ClusterIssuer {$state['issuer']}" : '').'. Custom domains of the region can use TLS mode cert-manager.'),
            'error' => ToolResult::error('The installation failed: '.($state['message'] ?? 'no details'), $state),
            'installing' => ToolResult::ok($state, 'Still installing (cert-manager is starting); cert_manager_status follows it.'),
            default => ToolResult::ok($state, 'cert-manager is '.($state['status'] ?? 'unknown').(isset($state['message']) ? ": {$state['message']}" : '').'.'),
        };
    }
}
