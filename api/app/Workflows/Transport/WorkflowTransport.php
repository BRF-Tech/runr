<?php

namespace App\Workflows\Transport;

/**
 * Sends one signed protocol request to a workflow function and returns its answer.
 */
interface WorkflowTransport
{
    /**
     * @param  array<string,string>  $headers
     * @param  bool  $override  $url is the app's endpoint override (set by its users): checked right
     *                          before the request and sent to the addresses that were checked
     *
     * @throws TransportException when the function cannot be reached, answers non-2xx, or the
     *                            override may not be called
     */
    public function send(string $url, string $body, array $headers, bool $override = false): TransportResponse;
}
