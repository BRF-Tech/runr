<?php

namespace App\Workflows\Transport;

/**
 * Sends one signed protocol request to a workflow function and returns its answer.
 */
interface WorkflowTransport
{
    /**
     * @param  array<string,string>  $headers
     *
     * @throws TransportException when the function cannot be reached or answers non-2xx
     */
    public function send(string $url, string $body, array $headers): TransportResponse;
}
