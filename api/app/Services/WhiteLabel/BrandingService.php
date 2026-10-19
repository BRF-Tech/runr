<?php

namespace App\Services\WhiteLabel;

use App\Models\Branding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Branding resolution, per field (docs/white-label.md):
 *
 *   tenant override (multi-tenant, when the platform allows it)
 *     ← platform override (Settings → Branding, stored in `brandings`)
 *     ← install brand (Helm values branding.* → env → config/whitelabel.php)
 *     ← RunR default (RunR by BRF Teknoloji)
 *
 * "Reset to default" in the admin UI removes the stored override, so the Helm value applies again.
 * The resolved shape is what GET /api/branding returns and what the mail theme renders.
 */
class BrandingService
{
    public const DEFAULT_PRODUCT = 'RunR';

    public const DEFAULT_COMPANY = 'BRF Teknoloji';

    public const DEFAULT_PRIMARY = '#f97316';

    public const DEFAULT_ACCENT = '#e63946';

    /** RunR's primary scale (logo / landing orange), RGB triplets */
    public const RUNR_PALETTE = [
        '300' => '253 186 116',
        '400' => '251 146 60',
        '500' => '249 115 22',
        '600' => '234 88 12',
        '700' => '194 65 12',
        '800' => '154 52 18',
        '900' => '124 45 18',
    ];

    public const SHADES = ['300', '400', '500', '600', '700', '800', '900'];

    /** Largest data: URI accepted for a logo / favicon (characters) */
    public const MAX_DATA_URI = 750000;

    /** Text / flag fields: name → validation rules (null resets the field to its default) */
    public const FIELDS = [
        'product_name' => ['nullable', 'string', 'max:80'],
        'company_name' => ['nullable', 'string', 'max:120'],
        'primary_color' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
        'primary_scale' => ['nullable', 'array'],
        'accent_color' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
        'login_headline' => ['nullable', 'string', 'max:120'],
        'login_text' => ['nullable', 'string', 'max:600'],
        'support_email' => ['nullable', 'email', 'max:190'],
        'support_url' => ['nullable', 'url:http,https', 'max:500'],
        'footer_links' => ['nullable', 'array', 'max:8'],
        'powered_by' => ['nullable', 'boolean'],
        'email_from_name' => ['nullable', 'string', 'max:120'],
        'logo_light_url' => ['nullable', 'string', 'max:'.self::MAX_DATA_URI],
        'logo_dark_url' => ['nullable', 'string', 'max:'.self::MAX_DATA_URI],
        'logo_mark_url' => ['nullable', 'string', 'max:'.self::MAX_DATA_URI],
        'favicon_url' => ['nullable', 'string', 'max:'.self::MAX_DATA_URI],
        'email_logo_url' => ['nullable', 'string', 'max:'.self::MAX_DATA_URI],
    ];

    /** Fields a tenant may not override (platform decisions) */
    public const PLATFORM_ONLY = ['powered_by'];

    /** Asset kind → URL field (an upload replaces the URL and vice versa) */
    public const URL_FIELD_OF_ASSET = [
        'logo_light' => 'logo_light_url',
        'logo_dark' => 'logo_dark_url',
        'logo_mark' => 'logo_mark_url',
        'favicon' => 'favicon_url',
        'email_logo' => 'email_logo_url',
    ];

    /** Allowed data: URI image types */
    private const DATA_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon'];

    /** Resolves a tenant slug to ['id', 'slug', 'name'] (registered by the Tenancy module) */
    private static ?\Closure $tenantResolver = null;

    /** Tenant whose branding the next mails use when sent outside its request (queued jobs) */
    private static ?string $mailTenantId = null;

    public static function resolveTenantsUsing(?\Closure $resolver): void
    {
        self::$tenantResolver = $resolver;
    }

    /**
     * @return array{id: string, slug: string, name: string}|null
     */
    public function findTenant(?string $slug): ?array
    {
        $slug = trim((string) $slug);
        if ($slug === '' || ! self::$tenantResolver || ! config('tenancy.enabled')) {
            return null;
        }

        $tenant = (self::$tenantResolver)($slug);

        return is_array($tenant) && isset($tenant['id']) ? $tenant : null;
    }

    // ── Resolution ──────────────────────────────────────────────────────────

    /**
     * Install brand (Helm values / env) over the RunR default.
     */
    public function defaults(): array
    {
        $c = (array) config('whitelabel.branding', []);

        $scale = self::normalizeScale(is_string($c['primary_scale'] ?? null) ? json_decode((string) $c['primary_scale'], true) : ($c['primary_scale'] ?? null));
        $primary = self::normalizeColor($c['primary_color'] ?? null)
            ?? (isset($scale['500']) ? self::hexOf($scale['500']) : null)
            ?? self::DEFAULT_PRIMARY;

        $defaults = [
            'product_name' => trim((string) ($c['product_name'] ?? '')) ?: self::DEFAULT_PRODUCT,
            'company_name' => trim((string) ($c['company_name'] ?? '')) ?: self::DEFAULT_COMPANY,
            'logo_light_url' => self::assetValue($c['logo_light'] ?? null),
            'logo_dark_url' => self::assetValue($c['logo_dark'] ?? null),
            'logo_mark_url' => self::assetValue($c['logo_mark'] ?? null),
            'favicon_url' => self::assetValue($c['favicon'] ?? null),
            'email_logo_url' => self::assetValue($c['email_logo'] ?? null),
            'primary_color' => $primary,
            'primary_scale' => $scale,
            'accent_color' => self::normalizeColor($c['accent_color'] ?? null) ?? self::DEFAULT_ACCENT,
            'email_from_name' => trim((string) ($c['email_from_name'] ?? '')) ?: null,
            'login_headline' => (string) ($c['login_headline'] ?? ''),
            'login_text' => (string) ($c['login_text'] ?? ''),
            'footer_links' => self::normalizeLinks(is_string($c['footer_links'] ?? null) ? json_decode($c['footer_links'], true) : ($c['footer_links'] ?? [])),
            'support_email' => (string) ($c['support_email'] ?? ''),
            'support_url' => (string) ($c['support_url'] ?? ''),
            'powered_by' => PlatformSettings::configBool($c['powered_by'] ?? true, true),
        ];

        return $defaults;
    }

    public function tenantOverridesEnabled(): bool
    {
        if (! config('tenancy.enabled')) {
            return false;
        }

        $stored = Branding::platform()?->settings['tenant_overrides'] ?? null;

        return $stored === null
            ? PlatformSettings::configBool(config('whitelabel.branding.tenant_overrides', true), true)
            : (bool) $stored;
    }

    /**
     * Platform branding (install brand ← platform override), with the computed fields.
     */
    public function platform(): array
    {
        return $this->finish($this->merge($this->defaults(), Branding::platform()));
    }

    /**
     * What a tenant's users see: platform branding ← the tenant's override (if allowed).
     */
    public function forTenant(?string $tenantId): array
    {
        $platform = $this->merge($this->defaults(), Branding::platform());
        if (! $tenantId || ! $this->tenantOverridesEnabled()) {
            return $this->finish($platform);
        }

        $tenant = Branding::forTenant($tenantId);

        return $this->finish($tenant ? $this->merge($platform, $tenant, self::PLATFORM_ONLY) : $platform);
    }

    /**
     * Run $callback with the mails it sends branded for $tenantId (null = platform branding).
     */
    public static function withMailTenant(?string $tenantId, callable $callback): mixed
    {
        $previous = self::$mailTenantId;
        self::$mailTenantId = $tenantId;

        try {
            return $callback();
        } finally {
            self::$mailTenantId = $previous;
        }
    }

    /**
     * Branding of the mail theme: the tenant's when a mail is sent for one (inside its request or
     * withMailTenant()), the platform's otherwise. `mail_logo_url` is absolute and never a data:
     * URI (mail clients block those) — data: logos are served by GET /api/branding/assets/{kind}.
     */
    public function forMail(): array
    {
        $tenantId = self::$mailTenantId;

        if ($tenantId === null && config('tenancy.enabled') && function_exists('tenancy')) {
            try {
                $tenantId = tenancy()->initialized ? (string) tenancy()->tenant?->getTenantKey() : null;
            } catch (\Throwable) {
                $tenantId = null;
            }
        }

        $branding = $tenantId ? $this->forTenant($tenantId) : $this->platform();
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $api = rtrim((string) config('app.url', $base), '/');

        $kind = $branding['email_logo_url'] ? 'email_logo' : ($branding['logo_light_url'] ? 'logo_light' : null);
        $logo = $kind ? $branding[self::URL_FIELD_OF_ASSET[$kind]] : null;

        if (is_string($logo) && str_starts_with($logo, 'data:')) {
            $logo = $api.'/api/branding/assets/'.$kind.($tenantId ? '?tenant_id='.rawurlencode($tenantId) : '');
        } elseif (is_string($logo) && str_starts_with($logo, '/')) {
            $logo = $base.$logo;
        }

        $branding['mail_logo_url'] = $logo;
        $branding['mail_from_name'] = $branding['email_from_name'] ?: $branding['product_name'];
        $branding['app_url'] = $base;

        return $branding;
    }

    /**
     * Public shape of GET /api/branding.
     */
    public function publicPayload(?array $tenant = null): array
    {
        $branding = $tenant ? $this->forTenant($tenant['id']) : $this->platform();
        $multi = (bool) config('tenancy.enabled');

        return $branding + [
            'tenant' => $tenant ? ['slug' => $tenant['slug'], 'name' => $tenant['name']] : null,
            // What the console shows (also in images built with every module): an optional module
            // disabled on the API (php artisan module:disable) never merges its config, so its flag is false
            'features' => [
                'multi_tenant' => $multi,
                'tenant_branding' => $multi && $this->tenantOverridesEnabled(),
                'billing' => PlatformSettings::configBool(config('billing.enabled'), false),
                'tickets' => PlatformSettings::configBool(config('ticket.enabled'), false),
            ],
        ];
    }

    /**
     * Admin view of one scope: resolved values, the stored overrides and the fallbacks.
     */
    public function adminPayload(?string $tenantId = null): array
    {
        $row = $tenantId ? Branding::forTenant($tenantId) : Branding::platform();

        $stored = array_intersect_key((array) ($row?->settings ?? []), self::FIELDS);
        foreach (self::URL_FIELD_OF_ASSET as $asset => $field) {
            $column = Branding::ASSETS[$asset];
            if ($row?->{$column}) {
                $stored[$field] = $this->assetUrl($row->{$column});
                $stored[$asset.'_uploaded'] = true;
            }
        }
        if ($tenantId) {
            $stored = array_diff_key($stored, array_flip(self::PLATFORM_ONLY));
        }

        return [
            'branding' => $tenantId ? $this->forTenant($tenantId) : $this->platform(),
            'stored' => $stored,
            'defaults' => $tenantId ? $this->platform() : $this->finish($this->defaults()),
            'tenant_overrides_enabled' => $this->tenantOverridesEnabled(),
        ];
    }

    /**
     * The binary of a branding asset (GET /api/branding/assets/{kind}): an uploaded file or a
     * data: URI. A URL answers as a redirect target.
     *
     * @return array{type: 'file'|'data'|'redirect', content?: string, mime?: string, url?: string}|null
     */
    public function asset(string $kind, ?string $tenantId = null): ?array
    {
        if (! isset(self::URL_FIELD_OF_ASSET[$kind])) {
            return null;
        }

        $tenantRow = $tenantId && $this->tenantOverridesEnabled() ? Branding::forTenant($tenantId) : null;
        $column = Branding::ASSETS[$kind];

        foreach ([$tenantRow, Branding::platform()] as $row) {
            if ($row?->{$column}) {
                $disk = Storage::disk($this->disk());
                if ($disk->exists($row->{$column})) {
                    return ['type' => 'file', 'content' => (string) $disk->get($row->{$column}), 'mime' => $disk->mimeType($row->{$column}) ?: 'application/octet-stream'];
                }
            }
        }

        $value = ($tenantId ? $this->forTenant($tenantId) : $this->platform())[self::URL_FIELD_OF_ASSET[$kind]] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (str_starts_with($value, 'data:')) {
            $decoded = self::decodeDataUri($value);

            return $decoded ? ['type' => 'data', 'content' => $decoded['content'], 'mime' => $decoded['mime']] : null;
        }

        return ['type' => 'redirect', 'url' => $value];
    }

    // ── Updates ─────────────────────────────────────────────────────────────

    /**
     * Apply a (partial) update. A field sent as null goes back to its default; fields not sent stay.
     *
     * @throws ValidationException
     */
    public function update(array $input, ?string $tenantId = null): Branding
    {
        $row = $tenantId ? Branding::tenantOrNew($tenantId) : Branding::platformOrNew();
        $settings = (array) ($row->settings ?? []);
        $allowed = $tenantId ? array_diff(array_keys(self::FIELDS), self::PLATFORM_ONLY) : array_keys(self::FIELDS);

        foreach ($allowed as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $value = $this->cleanField($field, $input[$field]);

            if ($value === null) {
                unset($settings[$field]);
            } else {
                $settings[$field] = $value;
            }

            // An explicit URL replaces an uploaded asset of the same kind
            $asset = array_search($field, self::URL_FIELD_OF_ASSET, true);
            if ($asset !== false && $value !== null) {
                $this->deleteAssetFile($row, $asset);
            }
        }

        if (! $tenantId && array_key_exists('tenant_overrides_enabled', $input)) {
            if ($input['tenant_overrides_enabled'] === null) {
                unset($settings['tenant_overrides']);
            } else {
                $settings['tenant_overrides'] = (bool) $input['tenant_overrides_enabled'];
            }
        }

        $row->settings = $settings;
        $row->save();

        return $row;
    }

    /**
     * Store an uploaded logo / favicon on the asset disk; replaces the previous file and any URL
     * override of the same kind.
     *
     * @throws ValidationException
     */
    public function storeAsset(string $asset, UploadedFile $file, ?string $tenantId = null): Branding
    {
        if (! isset(Branding::ASSETS[$asset])) {
            throw ValidationException::withMessages(['kind' => __('whitelabel.branding.unknown_asset')]);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');
        if ($extension === 'svg') {
            self::assertSafeSvg((string) file_get_contents($file->getRealPath()));
        }

        $row = $tenantId ? Branding::tenantOrNew($tenantId) : Branding::platformOrNew();
        $dir = 'branding/'.($tenantId ? 'tenant-'.preg_replace('/[^A-Za-z0-9_-]/', '', $tenantId) : 'platform');
        $path = $file->storeAs($dir, $asset.'-'.Str::lower(Str::random(12)).'.'.$extension, $this->disk());

        $this->deleteAssetFile($row, $asset);

        $settings = (array) ($row->settings ?? []);
        unset($settings[self::URL_FIELD_OF_ASSET[$asset]]);
        $row->settings = $settings;
        $row->{Branding::ASSETS[$asset]} = $path;
        $row->save();

        return $row;
    }

    public function deleteAsset(string $asset, ?string $tenantId = null): ?Branding
    {
        if (! isset(Branding::ASSETS[$asset])) {
            throw ValidationException::withMessages(['kind' => __('whitelabel.branding.unknown_asset')]);
        }

        $row = $tenantId ? Branding::forTenant($tenantId) : Branding::platform();
        if (! $row) {
            return null;
        }

        $this->deleteAssetFile($row, $asset);
        $row->save();

        return $row;
    }

    // ── Colours ─────────────────────────────────────────────────────────────

    /**
     * The primary scale 300–900 as RGB triplets: the explicit scale (missing shades expanded), the
     * RunR scale for the RunR base colour, otherwise expanded from the base colour (shade 500).
     *
     * @return array<string, string>
     */
    public static function palette(string $base, ?array $scale = null): array
    {
        $base = self::normalizeColor($base) ?? self::DEFAULT_PRIMARY;

        $expanded = $base === self::DEFAULT_PRIMARY ? self::RUNR_PALETTE : self::expand($base);

        if (! $scale) {
            return $expanded;
        }

        $out = [];
        foreach (self::SHADES as $shade) {
            $out[$shade] = $scale[$shade] ?? $expanded[$shade];
        }

        return $out;
    }

    /**
     * Shades from one base colour: lighter by mixing with white (300 40 %, 400 20 %), darker by
     * mixing with black (600 18 %, 700 36 %, 800 50 %, 900 62 %).
     *
     * @return array<string, string>
     */
    public static function expand(string $hex): array
    {
        [$r, $g, $b] = self::rgb($hex);
        $mix = fn (float $t, int $to) => array_map(fn ($c) => (int) round($c + ($to - $c) * $t), [$r, $g, $b]);

        $shades = [
            '300' => $mix(0.40, 255),
            '400' => $mix(0.20, 255),
            '500' => [$r, $g, $b],
            '600' => $mix(0.18, 0),
            '700' => $mix(0.36, 0),
            '800' => $mix(0.50, 0),
            '900' => $mix(0.62, 0),
        ];

        return array_map(fn ($c) => implode(' ', $c), $shades);
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim(self::normalizeColor($hex) ?? self::DEFAULT_PRIMARY, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /**
     * Scale input → RGB triplets per shade ("#rrggbb" or "r g b" / "r, g, b"); unknown keys and
     * invalid values are dropped; empty → null.
     *
     * @return array<string, string>|null
     */
    public static function normalizeScale(mixed $scale): ?array
    {
        if (! is_array($scale)) {
            return null;
        }

        $out = [];
        foreach (self::SHADES as $shade) {
            $value = $scale[$shade] ?? $scale[(int) $shade] ?? null;
            if ($value === null) {
                continue;
            }
            $triplet = self::triplet((string) $value);
            if ($triplet !== null) {
                $out[$shade] = $triplet;
            }
        }

        return $out === [] ? null : $out;
    }

    /** "0 159 227" → "#009fe3" */
    public static function hexOf(string $triplet): ?string
    {
        $triplet = self::triplet($triplet);
        if ($triplet === null) {
            return null;
        }

        return '#'.implode('', array_map(fn ($c) => str_pad(dechex((int) $c), 2, '0', STR_PAD_LEFT), explode(' ', $triplet)));
    }

    public static function triplet(string $value): ?string
    {
        $value = trim($value);

        if ($hex = self::normalizeColor($value)) {
            return implode(' ', self::rgb($hex));
        }

        if (preg_match('/^(?:rgb\()?\s*(\d{1,3})[\s,]+(\d{1,3})[\s,]+(\d{1,3})\s*\)?$/i', $value, $m)) {
            $parts = array_map('intval', array_slice($m, 1, 3));
            foreach ($parts as $part) {
                if ($part > 255) {
                    return null;
                }
            }

            return implode(' ', $parts);
        }

        return null;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function merge(array $base, ?Branding $row, array $skip = []): array
    {
        if (! $row) {
            return $base;
        }

        $settings = (array) ($row->settings ?? []);
        foreach (array_keys(self::FIELDS) as $field) {
            if (in_array($field, $skip, true) || ! array_key_exists($field, $settings) || $settings[$field] === null) {
                continue;
            }
            $base[$field] = $settings[$field];
        }

        foreach (self::URL_FIELD_OF_ASSET as $asset => $field) {
            $column = Branding::ASSETS[$asset];
            if ($row->{$column}) {
                $base[$field] = $this->assetUrl($row->{$column});
            }
        }

        return $base;
    }

    /**
     * Computed fields of a resolved branding: palette, accent triplet, default-brand flag.
     */
    private function finish(array $branding): array
    {
        $branding['palette'] = self::palette((string) $branding['primary_color'], $branding['primary_scale'] ?? null);
        $branding['accent_rgb'] = self::triplet((string) $branding['accent_color']) ?? self::RUNR_PALETTE['500'];
        $branding['is_default_brand'] = $branding['product_name'] === self::DEFAULT_PRODUCT;

        return $branding;
    }

    private function cleanField(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field) {
            'primary_color', 'accent_color' => self::normalizeColor($value),
            'primary_scale' => self::normalizeScale($value),
            'powered_by' => (bool) $value,
            'footer_links' => self::normalizeLinks($value),
            'logo_light_url', 'logo_dark_url', 'logo_mark_url', 'favicon_url', 'email_logo_url' => $this->cleanAssetUrl($field, (string) $value),
            default => trim((string) $value) === '' ? null : trim((string) $value),
        };
    }

    /**
     * Logo / favicon values: absolute http(s) URL, a path on this host ("/brand/logo.svg") or a
     * data:image/…;base64 URI (SVGs checked for scripts). Anything else (javascript:, other data:
     * types) is refused — the value ends up in <img src> and <link rel=icon>.
     */
    private function cleanAssetUrl(string $field, string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $clean = self::assetValue($value);
        if ($clean === null) {
            throw ValidationException::withMessages([$field => __('whitelabel.branding.invalid_url')]);
        }

        return $clean;
    }

    /**
     * Normalize a logo value from config / input: URL or host path as is, data: URI validated,
     * raw SVG markup turned into a data: URI. null when empty or not acceptable.
     */
    public static function assetValue(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://\S+$#i', $value) || preg_match('#^/(?!/)\S*$#', $value)) {
            return $value;
        }

        if (str_starts_with($value, '<svg') || str_starts_with($value, '<?xml')) {
            $value = 'data:image/svg+xml;base64,'.base64_encode($value);
        }

        if (str_starts_with($value, 'data:')) {
            return strlen($value) <= self::MAX_DATA_URI && self::decodeDataUri($value) !== null ? $value : null;
        }

        return null;
    }

    /**
     * @return array{mime: string, content: string}|null
     */
    public static function decodeDataUri(string $uri): ?array
    {
        if (! preg_match('#^data:([a-z0-9.+/-]+);base64,([A-Za-z0-9+/=\s]+)$#i', $uri, $m)) {
            return null;
        }

        $mime = strtolower($m[1]);
        if (! in_array($mime, self::DATA_TYPES, true)) {
            return null;
        }

        $content = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);
        if ($content === false || $content === '') {
            return null;
        }

        if ($mime === 'image/svg+xml' && ! self::isSafeSvg($content)) {
            return null;
        }

        return ['mime' => $mime, 'content' => $content];
    }

    /**
     * SVGs are served from this origin: refuse scripts, event handlers and embedded documents.
     */
    public static function isSafeSvg(string $svg): bool
    {
        $patterns = ['/<\s*script/i', '/\son[a-z]+\s*=/i', '/javascript\s*:/i', '/<\s*foreignObject/i', '/<\s*(iframe|embed|object)/i', '/<!ENTITY/i'];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $svg)) {
                return false;
            }
        }

        return true;
    }

    private static function assertSafeSvg(string $svg): void
    {
        if (! self::isSafeSvg($svg)) {
            throw ValidationException::withMessages(['file' => __('whitelabel.branding.unsafe_svg')]);
        }
    }

    private function deleteAssetFile(Branding $row, string $asset): void
    {
        $column = Branding::ASSETS[$asset];
        $path = $row->{$column};

        if ($path) {
            try {
                Storage::disk($this->disk())->delete($path);
            } catch (\Throwable) {
                // file already gone
            }
        }

        $row->{$column} = null;
    }

    public function assetUrl(string $path): string
    {
        return Storage::disk($this->disk())->url($path);
    }

    private function disk(): string
    {
        return (string) config('whitelabel.branding.asset_disk', 'public');
    }

    public static function normalizeColor(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if (! preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value)) {
            return null;
        }

        if (strlen($value) === 4) {
            $value = '#'.$value[1].$value[1].$value[2].$value[2].$value[3].$value[3];
        }

        return $value;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    public static function normalizeLinks(mixed $links): array
    {
        if (! is_array($links)) {
            return [];
        }

        $out = [];
        foreach (array_slice(array_values($links), 0, 8) as $link) {
            if (! is_array($link)) {
                continue;
            }
            $label = trim((string) ($link['label'] ?? ''));
            $url = trim((string) ($link['url'] ?? ''));
            if ($label === '' || ! preg_match('#^(https?://|mailto:)[^\s]+$#i', $url)) {
                continue;
            }
            $out[] = ['label' => mb_substr($label, 0, 60), 'url' => mb_substr($url, 0, 500)];
        }

        return $out;
    }
}
