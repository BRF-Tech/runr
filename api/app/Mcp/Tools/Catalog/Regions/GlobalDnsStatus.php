<?php

namespace App\Mcp\Tools\Catalog\Regions;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * The global zone and its servers (docs/global-projects.md): GET /global-dns.
 */
class GlobalDnsStatus extends Tool
{
    public function name(): string
    {
        return 'global_dns_status';
    }

    public function title(): string
    {
        return 'Global DNS status';
    }

    public function description(): string
    {
        return 'The global zone of global projects (<slug>.<zone>): the PowerDNS server of every region that runs one (status, public address, sync, certificate, GeoIP database of proximity mode: source and whether it is loaded), the NS and address records the operator creates in the parent zone to delegate it, and whether public DNS delegates it yet. Use it when a global host name does not resolve.';
    }

    public function inputSchema(): array
    {
        return Schema::object();
    }

    public function permissions(): array
    {
        return ['regions.view'];
    }

    public function group(): string
    {
        return 'regions';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->get('/global-dns');
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the global DNS');
        }

        $dns = (array) $result->data();
        $servers = (array) ($dns['servers'] ?? []);
        $ready = count(array_filter($servers, fn ($s) => ($s['status'] ?? '') === 'ready'));
        // the GeoIP database of proximity mode in each server (servers[].geoip)
        $geoip = array_filter(array_map(
            fn ($s) => is_array($s['geoip'] ?? null) ? ($s['region_name'] ?? '?').' '.($s['geoip']['status'] ?? 'unknown').' ('.($s['geoip']['source'] ?? 'default').')' : null,
            $servers,
        ));

        return ToolResult::ok($dns, sprintf(
            'Zone %s: %d of %d server(s) ready, delegation %s.%s',
            $dns['zone'] ?? '(none: no base domain)',
            $ready,
            count($servers),
            $dns['delegation']['status'] ?? 'unknown',
            $geoip !== [] ? ' GeoIP: '.implode(', ', $geoip).'.' : '',
        ));
    }
}
