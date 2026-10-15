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
        // Sanctum TransientToken, which is not a model
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof Model) {
            $token->forceFill([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_name' => $token->device_name ?: $this->parseDeviceName($request->userAgent()),
            ])->save();
        }

        return $response;
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
