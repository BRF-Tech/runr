<?php

namespace App\Services\Sandboxes\Exec;

use RuntimeException;

/**
 * The native exec client cannot run this command, and the command has not started: the kubeconfig
 * needs what only kubectl does (an exec plugin, an auth provider, files on disk, a proxy, …), or
 * the API server refused the WebSocket upgrade (no v5.channel.k8s.io where stdin must be closed,
 * an error kubectl reports in its own words). The caller runs the command with kubectl instead.
 */
class NativeExecUnsupported extends RuntimeException
{
    /**
     * @param  bool  $remember  the API server cannot take the native client at all (no WebSocket, no
     *                          v5 where stdin must end): SelectingExecTransport sends this server's
     *                          commands to kubectl for a while; false for this command only (a pod
     *                          that is gone, a refused credential, a connection that failed)
     */
    public function __construct(string $message, public readonly bool $remember = false)
    {
        parent::__construct($message);
    }
}
