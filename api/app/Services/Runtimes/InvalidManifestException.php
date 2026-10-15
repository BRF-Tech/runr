<?php

namespace App\Services\Runtimes;

use Illuminate\Http\JsonResponse;

/**
 * A runtime manifest that failed validation; problems are JSON-pointer paths into the manifest
 * ("/versions/0/image") with a message each.
 */
class InvalidManifestException extends \RuntimeException
{
    /**
     * @param  list<array{path: string, message: string}>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Invalid runtime manifest: '.implode('; ', array_map(
            fn ($p) => "{$p['path']} {$p['message']}",
            array_slice($problems, 0, 5),
        )));
    }

    /** Laravel renders the exception itself: 422 {message, errors: {manifest: [...]}, problems}. */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => __('api.runtimes.invalid_manifest'),
            'errors' => ['manifest' => array_map(fn ($p) => "{$p['path']}: {$p['message']}", $this->problems)],
            'problems' => $this->problems,
        ], 422);
    }
}
