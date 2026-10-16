<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackTokenSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only stored API tokens carry a session: a cookie-authenticated (stateful) request has a
        // Sanctum TransientToken, which is not a model. A token the request revoked (sign-out)
        // is a deleted model by now and is left alone.
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof Model && $token->exists) {
            $this->record($request, $token);
        }

        return $response;
    }

    /**
     * Client address, user agent and device of the session, written only when one changed. An
     * UPDATE of the token's row and never save(): saving a token deleted meanwhile would insert
     * it again — a signed-out or revoked session would be valid again.
     */
    private function record(Request $request, Model $token): void
    {
        $values = [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_name' => $token->getAttribute('device_name') ?: $this->parseDeviceName($request->userAgent()),
        ];

        $changed = array_filter($values, fn ($value, string $column) => $token->getAttribute($column) !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return;
        }

        $token->newQueryWithoutScopes()->whereKey($token->getKey())->update($changed);
    }

    private function parseDeviceName(?string $ua): string
    {
        if (! $ua) {
            return 'Unknown';
        }

        if (str_contains($ua, 'Mobile')) {
            return 'Mobile';
        }
        if (str_contains($ua, 'Tablet')) {
            return 'Tablet';
        }

        return 'Desktop';
    }
}
