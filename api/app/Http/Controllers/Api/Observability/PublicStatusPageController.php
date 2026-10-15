<?php

namespace App\Http\Controllers\Api\Observability;

use App\Http\Controllers\Api\Controller;
use App\Models\Observability\StatusPageRoute;
use App\Services\Observability\StatusPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public status pages — no sign-in: by slug (/status/<slug> in the console) or by the custom
 * domain the page is served on. Only pages switched public; components and incidents only.
 *
 * @tags Observability
 *
 * @unauthenticated
 */
class PublicStatusPageController extends Controller
{
    public function __construct(private StatusPageService $pages) {}

    /** GET /api/public/status-pages/{slug} */
    public function show(string $slug): JsonResponse
    {
        if (! preg_match('/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $slug)) {
            abort(404);
        }

        return $this->respond(StatusPageRoute::query()->where('slug', $slug)->first());
    }

    /** GET /api/public/status-pages?domain=<host> */
    public function byDomain(Request $request): JsonResponse
    {
        $domain = rtrim(strtolower(trim((string) $request->query('domain', ''))), '.');
        if ($domain === '' || strlen($domain) > 253) {
            abort(404);
        }

        return $this->respond(StatusPageRoute::query()->where('custom_domain', $domain)->first());
    }

    private function respond(?StatusPageRoute $route): JsonResponse
    {
        $payload = $route ? $this->pages->publicPayload($route) : null;
        if ($payload === null) {
            return response()->json(['message' => __('observability.status_page.not_found')], 404);
        }

        return response()->json(['data' => $payload])->header('Cache-Control', 'public, max-age=30');
    }
}
