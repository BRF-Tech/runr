<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\HorizonAccess;
use App\Support\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/horizon/link — a single-use sign-in link to the Horizon dashboard for the caller: a
 * platform administrator or, in the single-tenant edition, an Owner (App\Support\HorizonAccess),
 * with a console session or an unscoped API token — a token limited to some permissions cannot
 * open a dashboard that retries and deletes jobs.
 */
class HorizonLinkController extends Controller
{
    public function __invoke(Request $request, HorizonAccess $access, Rbac $rbac): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $access->isAdministrator($user) && $rbac->tokenAbilities($user) === null, 403);

        $link = $access->issue($user, (int) $request->integer('minutes', HorizonAccess::DEFAULT_MINUTES));

        return response()->json([
            'url' => $link['url'],
            'expires_at' => $link['expires_at']->toIso8601String(),
        ]);
    }
}
