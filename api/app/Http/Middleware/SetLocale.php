<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->header('Accept-Language', 'en');

        // "tr,en;q=0.9" → "tr"
        $locale = strtolower(substr($locale, 0, 2));

        if (in_array($locale, ['tr', 'en'])) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
