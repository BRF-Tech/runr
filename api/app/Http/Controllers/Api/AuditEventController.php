<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditEvent;
use App\Services\Audit\AuditCatalog;
use App\Services\Audit\AuditRetention;
use App\Support\Rbac\Rbac;
use App\Support\TenantAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Audit log API (docs/security.md, "Audit log").
 *
 *   GET /audit-events           list (filters below, paginated) — audit.view
 *   GET /audit-events/{id}      one event — audit.view
 *   GET /audit-events/export    CSV (default) or JSON (?format=json), same filters — audit.export
 *   GET /audit-settings         retention — audit.view
 *   PUT /audit-settings         retention — platform admin
 *
 * Filters: actor (user id, name or e-mail), action (exact, or "project.*" for a prefix, comma
 * separated), target_type, target_id, target (name contains), result, from / to (ISO 8601),
 * q (free text), tenant_id (platform admins outside a tenant; "platform" = platform events).
 *
 * Scope: in a tenant (tenant members, a platform admin with X-Tenant-ID) only that tenant's
 * events. Outside a tenant: single-tenant, every event; multi-tenant, platform admins only.
 * Route rules: config/route_permissions.php.
 */
class AuditEventController extends Controller
{
    private const CSV_COLUMNS = [
        'occurred_at', 'tenant_id', 'actor_type', 'actor_id', 'actor_name', 'actor_email', 'auth_method',
        'impersonator_id', 'impersonator_name', 'action', 'result', 'target_type', 'target_id', 'target_name',
        'ip_address', 'user_agent', 'request_id', 'http_method', 'route', 'status_code', 'metadata',
    ];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('audit.view');
        $this->validateFilters($request);

        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $page = $this->filtered($request)->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditEvent $e) => $e->toPublicArray())->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'actions' => AuditCatalog::actions(),
                'can_export' => Gate::allows('audit.export'),
                'scope' => TenantAccess::currentId() ? 'tenant' : 'platform',
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        Gate::authorize('audit.view');

        $event = $this->scoped($request)->whereKey($id)->firstOrFail();

        return response()->json(['data' => $event->toPublicArray()]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('audit.export');
        $this->validateFilters($request);

        $format = $request->query('format') === 'json' ? 'json' : 'csv';
        $max = max(1, (int) config('audit.export_max_rows', 100000));
        $query = $this->filtered($request)->orderByDesc('occurred_at')->orderByDesc('id')->limit($max);
        $filename = 'runr-audit-'.now()->format('Ymd-His').'.'.$format;

        return response()->streamDownload(function () use ($query, $format) {
            $out = fopen('php://output', 'w');

            if ($format === 'json') {
                fwrite($out, '[');
                $first = true;
                foreach ($query->cursor() as $event) {
                    fwrite($out, ($first ? '' : ',')."\n".json_encode($event->toPublicArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                    $first = false;
                }
                fwrite($out, "\n]\n");
            } else {
                fputcsv($out, self::CSV_COLUMNS);
                foreach ($query->cursor() as $event) {
                    fputcsv($out, array_map(fn (string $column) => $this->csvCell($event, $column), self::CSV_COLUMNS));
                }
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => $format === 'json' ? 'application/json' : 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function settings(): JsonResponse
    {
        Gate::authorize('audit.view');

        return response()->json([
            'retention_days' => app(AuditRetention::class)->retentionDays(),
            'max_days' => AuditRetention::MAX_DAYS,
            'forward_enabled' => (bool) config('audit.forward.enabled'),
            'can_update' => $this->mayChangeRetention(request()),
        ]);
    }

    /**
     * Retention is install-wide. Single-tenant: settings.manage (route rule). Multi-tenant:
     * a platform admin outside any tenant — a tenant's settings.manage covers the tenant only.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        if (! $this->mayChangeRetention($request)) {
            abort(403, __('api.audit.settings_forbidden'));
        }

        $data = $request->validate([
            'retention_days' => ['required', 'integer', 'min:0', 'max:'.AuditRetention::MAX_DAYS],
        ]);

        app(AuditRetention::class)->setRetentionDays((int) $data['retention_days']);

        return $this->settings();
    }

    private function mayChangeRetention(Request $request): bool
    {
        if (! config('tenancy.enabled')) {
            return Gate::allows('settings.manage');
        }

        return app(Rbac::class)->isPlatformAdmin($request->user()) && ! TenantAccess::currentId();
    }

    /** @return Builder<AuditEvent> */
    private function scoped(Request $request): Builder
    {
        $query = AuditEvent::query();
        $tenantId = TenantAccess::currentId();

        if ($tenantId) {
            return $query->where('tenant_id', $tenantId);
        }

        // Outside any tenant: single-tenant, the organization is the install (audit.view already
        // checked); multi-tenant, platform and cross-tenant events are the platform admins'
        if (config('tenancy.enabled') && ! app(Rbac::class)->isPlatformAdmin($request->user())) {
            return $query->whereRaw('1 = 0');
        }

        if ($filter = $request->query('tenant_id')) {
            $filter === 'platform' ? $query->whereNull('tenant_id') : $query->where('tenant_id', (string) $filter);
        }

        return $query;
    }

    /** @return Builder<AuditEvent> */
    private function filtered(Request $request): Builder
    {
        $query = $this->scoped($request);

        if ($actor = trim((string) $request->query('actor', ''))) {
            $query->where(function (Builder $q) use ($actor) {
                $q->where('actor_id', $actor)
                    ->orWhere('impersonator_id', $actor)
                    ->orWhereLike('actor_name', '%'.$this->like($actor).'%')
                    ->orWhereLike('actor_email', '%'.$this->like($actor).'%');
            });
        }

        if ($actions = trim((string) $request->query('action', ''))) {
            $query->where(function (Builder $q) use ($actions) {
                foreach (array_filter(array_map('trim', explode(',', $actions))) as $action) {
                    str_ends_with($action, '*')
                        ? $q->orWhereLike('action', $this->like(rtrim($action, '*')).'%')
                        : $q->orWhere('action', $action);
                }
            });
        }

        foreach (['target_type', 'target_id', 'result', 'request_id'] as $exact) {
            if (($value = $request->query($exact)) !== null && $value !== '') {
                $query->where($exact, (string) $value);
            }
        }

        if ($target = trim((string) $request->query('target', ''))) {
            $query->whereLike('target_name', '%'.$this->like($target).'%');
        }

        if ($from = $request->query('from')) {
            $query->where('occurred_at', '>=', Carbon::parse($from));
        }
        if ($to = $request->query('to')) {
            $query->where('occurred_at', '<=', Carbon::parse($to));
        }

        if ($text = trim((string) $request->query('q', ''))) {
            $like = '%'.$this->like($text).'%';
            $query->where(function (Builder $q) use ($like) {
                $q->whereLike('action', $like)
                    ->orWhereLike('actor_name', $like)
                    ->orWhereLike('actor_email', $like)
                    ->orWhereLike('target_name', $like)
                    ->orWhereLike('ip_address', $like);
            });
        }

        return $query;
    }

    private function validateFilters(Request $request): void
    {
        $request->validate([
            'result' => 'nullable|in:'.implode(',', AuditEvent::RESULTS),
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
            'format' => 'nullable|in:csv,json',
        ]);
    }

    /** LIKE operand (case-insensitive whereLike: ILIKE on PostgreSQL, LIKE on SQLite). */
    private function like(string $value): string
    {
        return mb_substr($value, 0, 200);
    }

    /** CSV cell, with spreadsheet formula injection neutralized (OWASP). */
    private function csvCell(AuditEvent $event, string $column): string
    {
        $value = match ($column) {
            'occurred_at' => $event->occurred_at->toISOString(),
            'metadata' => $event->metadata ? json_encode($event->metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
            default => $event->{$column},
        };
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
