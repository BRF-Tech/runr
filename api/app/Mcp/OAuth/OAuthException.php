<?php

namespace App\Mcp\OAuth;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** An OAuth 2.1 error answer: {"error": "...", "error_description": "..."} with its status. */
final class OAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct($description);
    }

    public function toResponse(): JsonResponse
    {
        $response = new JsonResponse(['error' => $this->error, 'error_description' => $this->getMessage()], $this->status, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
        if ($this->status === 401) {
            $response->headers->set('WWW-Authenticate', 'Basic realm="oauth"');
        }

        return $response;
    }
}
