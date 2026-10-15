<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAccountDisabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_disabled) {
            return response()->json([
                'message' => __('api.account.account_disabled'),
            ], 403);
        }

        return $next($request);
    }
}
