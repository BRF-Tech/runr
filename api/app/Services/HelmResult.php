<?php

namespace App\Services;

class HelmResult
{
    /**
     * Errors of the Kubernetes API server, or of the way to it, that a later try may not meet:
     * etcd timeouts and leader changes behind the API server, its 5xx and 429 answers, client-side
     * timeouts and dropped connections. Matched case-insensitively in Helm's error output. Not
     * among them: Helm's own --wait running out ("context deadline exceeded", "timed out waiting
     * for the condition"), a refused connection, and every failure of the release itself.
     */
    public const TRANSIENT_API_ERRORS = [
        // etcd behind the API server
        'etcdserver: request timed out',
        'etcdserver: leader changed',
        'etcdserver: no leader',
        'etcdserver: too many requests',
        // the API server's answers: 504, 503, other 5xx, 500, 429
        'the server was unable to return a response in the time allotted',
        'Timeout: request did not complete within requested timeout',
        'the server is currently unable to handle the request',
        'an error on the server',
        'Internal error occurred',
        'the server has received too many requests',
        // the way to it
        'i/o timeout',
        'TLS handshake timeout',
        'Client.Timeout exceeded',
        'connection reset by peer',
        'http2: client connection lost',
        'unexpected EOF',
        'broken pipe',
    ];

    public function __construct(
        public readonly bool $success,
        public readonly string $output,
        public readonly string $error,
        public readonly int $exitCode,
    ) {}

    /** A failure on a transient error of the Kubernetes API server (TRANSIENT_API_ERRORS). */
    public function transientApiError(): bool
    {
        if ($this->success) {
            return false;
        }

        foreach (self::TRANSIENT_API_ERRORS as $marker) {
            if (stripos($this->error, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /** The error on one line (Helm writes "Error: 1 error occurred:\n\t* …"), for a log line. */
    public function errorLine(int $width = 300): string
    {
        return mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', $this->error)), 0, $width, '…');
    }
}
