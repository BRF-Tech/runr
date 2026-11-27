<?php

namespace App\Services\Kubeconfigs;

use RuntimeException;

/**
 * The kubeconfig of an organization's region is not used (KubeconfigPolicy, TenantKubeconfigs):
 * the message is translated (regions.kubeconfig.<reason>) and names the field, never an address
 * the server name resolved to.
 */
final class KubeconfigRefused extends RuntimeException
{
    /**
     * @param  string  $reason  regions.kubeconfig.<reason>
     * @param  array<string, scalar>  $replace
     */
    public function __construct(public readonly string $reason, array $replace = [])
    {
        parent::__construct((string) __('regions.kubeconfig.'.$reason, $replace));
    }
}
