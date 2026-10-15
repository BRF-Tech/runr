<?php

namespace App\Services\Observability;

use App\Models\Observability\StatusPage;
use App\Models\Observability\StatusPageRoute;
use App\Models\Observability\UptimeCheck;
use App\Models\Observability\UptimeIncident;
use App\Services\Observability\Uptime\UptimeService;
use App\Services\WhiteLabel\BrandingService;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;

/**
 * The organization's public status page (one per tenant): its settings in the tenant's schema,
 * the slug / custom domain in the central lookup (StatusPageRoute), and the public payload —
 * components (uptime checks shown on the page), their daily uptime and the incident history.
 */
class StatusPageService
{
    /** The organization's page; an unsaved default when it has none. */
    public function current(): StatusPage
    {
        return StatusPage::query()->first() ?? new StatusPage([
            'slug' => $this->suggestSlug(),
            'title' => __('observability.status_page.default_title'),
            'is_public' => false,
            'show_incident_history' => true,
            'history_days' => (int) config('observability.status_page.history_days', 90),
        ]);
    }

    /** Slug / custom domain taken by another organization's page? */
    public function conflicts(string $slug, ?string $domain, ?string $ownPageId): array
    {
        $errors = [];
        $tenant = TenantScope::current();

        $taken = fn (string $column, string $value) => StatusPageRoute::query()
            ->where($column, $value)
            ->get()
            ->contains(fn (StatusPageRoute $r) => ! ($r->status_page_id === $ownPageId && $r->tenant_id === $tenant));

        if ($taken('slug', $slug)) {
            $errors['slug'] = __('observability.status_page.slug_taken');
        }
        if ($domain && $taken('custom_domain', $domain)) {
            $errors['custom_domain'] = __('observability.status_page.domain_taken');
        }

        return $errors;
    }

    public function save(array $data): StatusPage
    {
        $page = $this->current();
        $page->fill($data);
        $page->save();

        StatusPageRoute::query()->updateOrCreate(
            ['status_page_id' => $page->id, 'tenant_id' => TenantScope::current()],
            ['slug' => $page->slug, 'custom_domain' => $page->custom_domain ?: null, 'is_public' => $page->is_public],
        );

        return $page;
    }

    /**
     * Public payload of a route (slug or custom domain); null when the page is not public.
     */
    public function publicPayload(StatusPageRoute $route): ?array
    {
        if (! $route->is_public || ! config('observability.status_page.enabled', true)) {
            return null;
        }

        $payload = TenantScope::run($route->tenant_id, function () use ($route) {
            $page = StatusPage::query()->find($route->status_page_id);
            if (! $page || ! $page->is_public) {
                return null;
            }

            return $this->payload($page);
        });

        return $payload === null ? null : $payload + ['branding' => self::branding($route->tenant_id)];
    }

    /**
     * The organization's branding (white-label), the fields a public page shows: product name,
     * logos, colours, support link. Null without the branding service.
     */
    public static function branding(?string $tenantId): ?array
    {
        if (! class_exists(BrandingService::class)) {
            return null;
        }

        try {
            $b = app(BrandingService::class)->forTenant($tenantId);
        } catch (\Throwable) {
            return null;
        }

        return array_intersect_key($b, array_flip([
            'product_name', 'company_name', 'primary_color', 'accent_color', 'palette',
            'logo_light_url', 'logo_dark_url', 'logo_mark_url', 'favicon_url', 'support_url', 'powered_by',
        ]));
    }

    /** The page as the public sees it (also the preview of the settings tab). */
    public function payload(StatusPage $page): array
    {
        $days = max(1, min(90, (int) $page->history_days));
        $checks = UptimeCheck::query()->where('show_on_status_page', true)->orderBy('status_page_order')->orderBy('name')->get();

        $components = [];
        foreach ($checks as $check) {
            $components[] = [
                'id' => $check->id,
                'name' => $check->publicName(),
                'status' => self::componentStatus($check),
                'uptime_24h' => UptimeService::uptimePercent($check, 24),
                'uptime_30d' => UptimeService::uptimePercent($check, 24 * 30),
                'uptime_90d' => UptimeService::uptimePercent($check, 24 * $days),
                'days' => array_map(fn ($d) => ['date' => $d['date'], 'uptime' => $d['uptime']], UptimeService::dailyBars($check, $days)),
            ];
        }

        $incidents = [];
        $ids = $checks->pluck('id');
        if ($ids->isNotEmpty()) {
            $query = UptimeIncident::query()->whereIn('uptime_check_id', $ids)->orderByDesc('started_at');
            if (! $page->show_incident_history) {
                $query->where('status', UptimeIncident::OPEN);
            } else {
                $query->where('started_at', '>=', now()->subDays($days));
            }
            $names = $checks->mapWithKeys(fn ($c) => [$c->id => $c->publicName()]);
            foreach ($query->limit(100)->get() as $incident) {
                $incidents[] = $incident->toApi(true) + ['component' => $names[$incident->uptime_check_id] ?? null];
            }
        }

        $statuses = array_column($components, 'status');
        $overall = match (true) {
            $statuses === [] => 'unknown',
            in_array('outage', $statuses, true) => count(array_filter($statuses, fn ($s) => $s === 'outage')) === count($statuses) ? 'major_outage' : 'partial_outage',
            in_array('unknown', $statuses, true) => 'unknown',
            default => 'operational',
        };

        return [
            'slug' => $page->slug,
            'title' => $page->title,
            'description' => $page->description,
            'logo_url' => $page->logo_url,
            'status' => $overall,
            'components' => $components,
            'incidents' => $incidents,
            'history_days' => $days,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public static function componentStatus(UptimeCheck $check): string
    {
        if (! $check->is_enabled) {
            return 'unknown';
        }

        return match ($check->status) {
            UptimeCheck::STATUS_UP => 'operational',
            UptimeCheck::STATUS_DOWN => 'outage',
            default => 'unknown',
        };
    }

    private function suggestSlug(): string
    {
        $tenant = TenantScope::current();
        if ($tenant && class_exists(Tenant::class)) {
            $slug = Tenant::query()->find($tenant)?->slug;
            if ($slug) {
                return Str::slug($slug);
            }
        }

        return 'status';
    }
}
