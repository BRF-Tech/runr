<?php

namespace App\Http\Controllers\Api\WhiteLabel;

use App\Http\Controllers\Api\Controller;
use App\Services\WhiteLabel\BrandingService;
use App\Support\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Branding (white-label, docs/white-label.md).
 *
 *  GET    /api/branding?tenant=<slug>          public — the login page is branded before sign-in
 *  GET    /api/branding/assets/{kind}          public — uploaded file / decoded data: URI / redirect
 *  GET    /api/admin/branding                  branding.manage (multi-tenant: platform administrators)
 *  PUT    /api/admin/branding                  same — partial update, null = default
 *  POST   /api/admin/branding/assets/{kind}    same — multipart "file" (logo_light|logo_dark|logo_mark|favicon|email_logo)
 *  DELETE /api/admin/branding/assets/{kind}    same
 */
class BrandingController extends Controller
{
    public function __construct(private readonly BrandingService $branding) {}

    /** @unauthenticated */
    public function show(Request $request): JsonResponse
    {
        $tenant = $this->branding->findTenant($request->query('tenant'));

        return response()->json($this->branding->publicPayload($tenant))
            ->header('Cache-Control', 'public, max-age=60');
    }

    /**
     * A branding image (logo / mark / favicon / e-mail logo) by kind — uploaded file or data: URI
     * decoded; a URL value redirects. Used where a data: URI cannot be (e-mails).
     *
     * @unauthenticated
     */
    public function asset(Request $request, string $kind): Response|RedirectResponse
    {
        $tenantId = $request->query('tenant_id');
        if (! $tenantId && $request->query('tenant')) {
            $tenantId = $this->branding->findTenant((string) $request->query('tenant'))['id'] ?? null;
        }

        $asset = $this->branding->asset($kind, is_string($tenantId) && $tenantId !== '' ? $tenantId : null);
        abort_if($asset === null, 404);

        if ($asset['type'] === 'redirect') {
            return redirect()->away($asset['url']);
        }

        return response($asset['content'], 200, [
            'Content-Type' => $asset['mime'],
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            // An SVG opened directly must not run anything
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeInstallBrand($request);

        return response()->json($this->branding->adminPayload());
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeInstallBrand($request);
        $data = $request->validate(self::rules(true));

        $this->branding->update($data);

        return response()->json($this->branding->adminPayload());
    }

    public function uploadAsset(Request $request, string $kind): JsonResponse
    {
        $this->authorizeInstallBrand($request);
        $request->validate(self::assetRules());

        $this->branding->storeAsset($kind, $request->file('file'));

        return response()->json($this->branding->adminPayload());
    }

    public function deleteAsset(Request $request, string $kind): JsonResponse
    {
        $this->authorizeInstallBrand($request);
        $this->branding->deleteAsset($kind);

        return response()->json($this->branding->adminPayload());
    }

    /**
     * The route requires branding.manage (config/route_permissions.php). In the multi-tenant
     * edition that permission is the tenant's own brand (/api/tenant/branding): the install's brand
     * stays with the platform administrators.
     */
    private function authorizeInstallBrand(Request $request): void
    {
        if (config('tenancy.enabled')) {
            abort_unless(app(Rbac::class)->isPlatformAdmin($request->user()), 403, __('rbac.platform_admin_required'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $platform): array
    {
        $rules = BrandingService::FIELDS + [
            'primary_scale.*' => ['nullable', 'string', 'max:32'],
            'footer_links.*' => ['array'],
            'footer_links.*.label' => ['required', 'string', 'max:60'],
            'footer_links.*.url' => ['required', 'string', 'max:500', 'regex:#^(https?://|mailto:)\S+$#i'],
        ];

        if ($platform) {
            $rules['tenant_overrides_enabled'] = ['nullable', 'boolean'];
        } else {
            foreach (BrandingService::PLATFORM_ONLY as $field) {
                unset($rules[$field]);
            }
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public static function assetRules(): array
    {
        $maxKb = max(16, (int) config('whitelabel.branding.asset_max_kb', 1024));

        return [
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimes:png,jpg,jpeg,webp,svg,ico'],
        ];
    }
}
