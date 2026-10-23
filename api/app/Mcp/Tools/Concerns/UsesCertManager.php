<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/**
 * cert-manager of a region (docs/regions.md#cert-manager) for the cert_manager_* tools: the status
 * read through GET /knative-regions/{id}/cert-manager and the wait for a queued install.
 */
trait UsesCertManager
{
    use ReadsProjects;

    /** cert-manager of a region; $cluster adds what the cluster runs (issuer Ready, region CA). */
    protected function certManagerState(ToolContext $context, string $regionId, bool $cluster = false): array
    {
        $result = $context->api()->get(InternalApi::path('knative-regions', $regionId, 'cert-manager'), ['cluster' => $cluster ? 1 : 0]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading cert-manager of the region');
        }

        return (array) $result->json;
    }

    /** Polls while the region is installing, with the step log as progress. */
    protected function waitForCertManager(ToolContext $context, string $regionId, int $deadline): array
    {
        $state = $this->certManagerState($context, $regionId);
        $step = 0;
        while (in_array($state['status'] ?? null, ['installing', 'uninstalling'], true)) {
            $context->progress(++$step, null, self::certManagerLastLine($state['message'] ?? null) ?? ucfirst((string) $state['status']).'…');
            if (time() >= $deadline || ! $context->pause()) {
                break;
            }
            $state = $this->certManagerState($context, $regionId);
        }

        return $state;
    }

    /** The concise form of the status. */
    protected static function certManagerSummary(array $state): array
    {
        $issuer = is_array($state['issuer'] ?? null) ? $state['issuer'] : [];
        $acme = is_array($state['acme'] ?? null) ? $state['acme'] : [];
        $domains = is_array($state['domains'] ?? null) ? $state['domains'] : [];
        $cluster = is_array($state['cluster'] ?? null) ? $state['cluster'] : null;

        return array_filter([
            'status' => $state['status'] ?? null,
            'message' => self::certManagerLastLine($state['message'] ?? null),
            'installed_by_runr' => $state['managed'] ?? null,
            'version' => $state['version'] ?? null,
            'namespace' => $state['namespace'] ?? null,
            'issuer' => $issuer['name'] ?? null,
            'issuer_kind' => $issuer['kind'] ?? null,
            'issuer_source' => $issuer['source'] ?? null,
            'issuer_mode' => $state['issuer_mode'] ?? null,
            'default_issuer_plan' => isset($state['plan']['kind']) ? $state['plan']['kind'].': '.($state['plan']['reason'] ?? '') : null,
            'acme_email' => $acme['email'] ?? null,
            'publicly_reachable' => $acme['public'] ?? null,
            'custom_domains' => $domains === [] ? null : array_filter([
                'cert_manager_mode_available' => $domains['available'] ?? null,
                'reason' => $domains['reason'] ?? null,
                'ca_download' => $domains['ca'] ?? null,
            ], fn ($v) => $v !== null),
            'cluster' => $cluster === null ? null : array_filter([
                'runs' => $cluster['available'] ?? null,
                'issuer_ready' => $cluster['issuer_ready'] ?? null,
                'issuer_message' => $cluster['issuer_message'] ?? null,
                'ca_available' => $cluster['ca_available'] ?? null,
                'drift' => $cluster['drift'] ?? null,
            ], fn ($v) => $v !== null && $v !== []),
            'cluster_error' => $state['cluster_error'] ?? null,
            'can_manage' => $state['can_manage'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    private static function certManagerLastLine(mixed $message): ?string
    {
        if (! is_string($message) || trim($message) === '') {
            return null;
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $message) ?: [])));

        return $lines === [] ? null : mb_substr((string) end($lines), 0, 500);
    }
}
