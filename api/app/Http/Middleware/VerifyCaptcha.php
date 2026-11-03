<?php

namespace App\Http\Middleware;

use App\Services\Captcha\CaptchaGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `captcha.verify:<form>` (docs/security.md#bot-protection): the bot protection
 * of an anonymous form — login (a shared account's sign-in counts as demo_login), public_forms.
 * The sign-up form checks in App\Http\Requests\Auth\RegisterRequest instead, once its other fields
 * are valid (a typo must not spend the single-use token). Answers 422 / 409 / 503 itself
 * (App\Services\Captcha\CaptchaGate); otherwise the request goes on.
 */
class VerifyCaptcha
{
    public function __construct(private readonly CaptchaGate $gate) {}

    public function handle(Request $request, Closure $next, string $form): Response
    {
        return $this->gate->check($request, $form) ?? $next($request);
    }
}
