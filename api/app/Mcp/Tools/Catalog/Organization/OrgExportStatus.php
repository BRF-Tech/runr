<?php

namespace App\Mcp\Tools\Catalog\Organization;

use App\Mcp\Server\InternalApi;
use App\Mcp\Support\McpUrls;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/** Status of an organization export or import (docs/org-export.md): progress, package, report. */
class OrgExportStatus extends Tool
{
    public function name(): string
    {
        return 'org_export_status';
    }

    public function title(): string
    {
        return 'Organization transfer status';
    }

    public function description(): string
    {
        return 'Status of an organization export (or import): phase, progress, the package (file name, size, SHA-256, where to download it) and the report once finished. Without a transfer id, lists the latest exports and imports.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'transfer' => Schema::string('Transfer id (org_export_start); leave out to list the latest transfers.', ['maxLength' => 26]),
        ]);
    }

    public function permissions(): array
    {
        return ['org.transfer'];
    }

    public function group(): string
    {
        return 'organization';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        if (empty($arguments['transfer'])) {
            $result = $context->api()->get('org-transfers');
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'Transfers not listed');
            }
            $items = array_map(fn ($t) => self::summary((array) $t), (array) $result->get('data', []));

            return ToolResult::ok(['transfers' => $items], count($items).' transfer(s).');
        }

        $id = (string) $arguments['transfer'];
        $result = $context->api()->get(InternalApi::path('org-transfers', $id));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, "Transfer {$id}");
        }
        $transfer = (array) $result->get('data', []);
        $summary = self::summary($transfer);
        if (! empty($transfer['package']['downloadable'])) {
            // Needs a bearer token of an owner (runr org transfer / the console download it too)
            $summary['download_url'] = McpUrls::base().'/api/org-transfers/'.$id.'/download';
        }
        if (! empty($transfer['report'])) {
            $report = (array) $transfer['report'];
            $summary['report'] = array_filter([
                'counts' => $report['counts'] ?? null,
                'entries' => $report['entries'] ?? null,
                'manual_steps' => $report['manual_steps'] ?? null,
                'warnings' => $report['warnings'] ?? null,
                'skipped' => $report['skipped'] ?? null,
                'failed' => array_values(array_filter((array) ($report['items'] ?? []), fn ($i) => ($i['status'] ?? null) === 'failed')),
            ], fn ($v) => $v !== null && $v !== []);
        }

        return ToolResult::ok(['transfer' => $summary], ucfirst((string) ($transfer['kind'] ?? 'transfer')).' '.$id.' is '.($transfer['status'] ?? '?').' ('.($transfer['progress'] ?? 0).'%).');
    }

    /** The fields an agent needs, without plan and report bodies. */
    public static function summary(array $transfer): array
    {
        return array_filter([
            'id' => $transfer['id'] ?? null,
            'kind' => $transfer['kind'] ?? null,
            'status' => $transfer['status'] ?? null,
            'phase' => $transfer['phase'] ?? null,
            'progress' => $transfer['progress'] ?? null,
            'message' => $transfer['message'] ?? null,
            'package' => $transfer['package'] ?? null,
            'destination' => $transfer['destination'] ?? null,
            'source' => $transfer['source'] ?? null,
            'received_bytes' => isset($transfer['upload']['uploaded_bytes']) && ($transfer['status'] ?? null) === 'fetching' ? $transfer['upload']['uploaded_bytes'] : null,
            'created_at' => $transfer['created_at'] ?? null,
            'finished_at' => $transfer['finished_at'] ?? null,
            'expires_at' => $transfer['expires_at'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
