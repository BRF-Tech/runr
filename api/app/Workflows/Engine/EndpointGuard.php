<?php

namespace App\Workflows\Engine;

use App\Services\Observability\OutboundTarget;
use App\Services\Observability\OutboundUrlGuard;

/**
 * Validates a user-supplied endpoint override before the engine sends signed requests to it:
 * http(s) only, no credentials, and — unless workflows.allow_private_endpoints — no loopback,
 * private, link-local or otherwise reserved address (literal or resolved), so an override cannot
 * point the engine at the platform's own network. The ranges and the pin are RunR's outbound
 * guard (OutboundUrlGuard): assertAllowed() when the override is saved, target() before every
 * request to it (HttpWorkflowTransport), which pins the request to the addresses it checked.
 */
class EndpointGuard
{
    public function __construct(private OutboundUrlGuard $guard) {}

    /**
     * The override may be saved. A name that does not resolve (yet) may: nothing to judge, and
     * every request checks it again.
     *
     * @throws WorkflowException
     */
    public function assertAllowed(string $url): void
    {
        $target = $this->guard->target($url, (bool) config('workflows.allow_private_endpoints', false));
        if (! $target->allowed() && $target->code !== 'unresolvable') {
            throw self::exception($target);
        }
    }

    /**
     * The check of a request to the override: the target whose options() the request is sent with.
     *
     * @throws WorkflowException
     */
    public function target(string $url): OutboundTarget
    {
        $target = $this->guard->target($url, (bool) config('workflows.allow_private_endpoints', false));
        if (! $target->allowed()) {
            throw self::exception($target);
        }

        return $target;
    }

    /**
     * The check of a request to the project's own function URL: RunR's, called as it is — unless
     * its function domain is under the organization's own base domain (OutboundUrlGuard::project()).
     *
     * @throws WorkflowException
     */
    public function functionTarget(string $url): OutboundTarget
    {
        $target = $this->guard->project($url, (bool) config('workflows.allow_private_endpoints', false));
        if (! $target->allowed()) {
            throw self::exception($target);
        }

        return $target;
    }

    public static function isPublicIp(string $ip): bool
    {
        return OutboundUrlGuard::isPublicIp($ip);
    }

    private static function exception(OutboundTarget $target): WorkflowException
    {
        return match ($target->code) {
            'credentials' => WorkflowException::invalid(__('workflows.endpoint_credentials'), 'endpoint_invalid'),
            'private' => WorkflowException::invalid(__('workflows.endpoint_private'), 'endpoint_private'),
            'unresolvable' => WorkflowException::invalid((string) $target->reason, 'endpoint_unresolvable'),
            default => WorkflowException::invalid(__('workflows.endpoint_invalid'), 'endpoint_invalid'),
        };
    }
}
