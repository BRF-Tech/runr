<?php

namespace App\Http\Controllers\Api\Observability;

use App\Http\Controllers\Api\Controller;
use App\Jobs\DeliverAlertJob;
use App\Models\KnativeRegion;
use App\Models\Observability\AlertChannel;
use App\Models\Observability\AlertDelivery;
use App\Models\Observability\AlertEvent;
use App\Models\Observability\AlertRule;
use App\Models\Project;
use App\Services\Observability\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alert rules (what fires, severity, cooldown, channels), the fired alerts and the delivery log.
 *
 * @tags Observability
 */
class AlertRuleController extends Controller
{
    /** GET /api/observability/alert-rules */
    public function index(): JsonResponse
    {
        return response()->json(['data' => AlertRule::query()->orderBy('name')->get()->map(fn (AlertRule $r) => $r->toApi())->all()]);
    }

    /** POST /api/observability/alert-rules */
    public function store(Request $request): JsonResponse
    {
        $rule = AlertRule::query()->create($this->validated($request, null) + ['state' => AlertRule::STATE_OK]);

        return response()->json(['data' => $rule->toApi()], 201);
    }

    /** PUT /api/observability/alert-rules/{rule} */
    public function update(Request $request, string $rule): JsonResponse
    {
        $model = AlertRule::query()->findOrFail($rule);
        $model->fill($this->validated($request, $model))->save();

        return response()->json(['data' => $model->toApi()]);
    }

    /** DELETE /api/observability/alert-rules/{rule} — its firing alerts are closed silently. */
    public function destroy(Request $request, string $rule): Response
    {
        $model = AlertRule::query()->findOrFail($rule);
        AlertEvent::query()->where('alert_rule_id', $model->id)->where('status', AlertEvent::FIRING)
            ->update(['status' => AlertEvent::RESOLVED, 'resolved_at' => now()]);
        $model->delete();

        return response()->noContent();
    }

    /** GET /api/observability/alert-events — ?status=firing|resolved */
    public function events(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in([AlertEvent::FIRING, AlertEvent::RESOLVED])]]);

        $events = AlertEvent::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('fired_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $events->map(fn (AlertEvent $e) => $e->toApi())->all()]);
    }

    /** GET /api/observability/alert-deliveries — the delivery log, ?channel_id=, ?status= */
    public function deliveries(Request $request): JsonResponse
    {
        $request->validate([
            'channel_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::in([AlertDelivery::PENDING, AlertDelivery::SENT, AlertDelivery::FAILED])],
        ]);

        $page = AlertDelivery::query()
            ->with(['channel', 'event'])
            ->when($request->filled('channel_id'), fn ($q) => $q->where('alert_channel_id', $request->input('channel_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (AlertDelivery $d) => $d->toApi())->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** POST /api/observability/alert-deliveries/{delivery}/retry — send a failed delivery again. */
    public function retry(Request $request, string $delivery): JsonResponse
    {
        $model = AlertDelivery::query()->findOrFail($delivery);
        if ($model->status !== AlertDelivery::FAILED || $model->kind === AlertDelivery::KIND_TEST) {
            return response()->json(['message' => __('observability.alerts.retry_failed_only')], 409);
        }

        $model->forceFill(['status' => AlertDelivery::PENDING, 'next_attempt_at' => null])->save();
        DeliverAlertJob::dispatch($model->id, TenantScope::current());

        return response()->json(['data' => $model->refresh()->load(['channel', 'event'])->toApi()], 202);
    }

    private function validated(Request $request, ?AlertRule $current): array
    {
        $type = $request->input('type', $current?->type);

        $data = $request->validate([
            'name' => [$current ? 'sometimes' : 'required', 'string', 'max:100'],
            'type' => [$current ? 'prohibited' : 'required', Rule::in(AlertRule::TYPES)],
            'project_id' => ['nullable', 'string', 'size:26'],
            'region_id' => ['nullable', 'string', 'size:26'],
            'params' => ['nullable', 'array'],
            'severity' => ['nullable', Rule::in(AlertRule::SEVERITIES)],
            'cooldown_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'channel_ids' => ['nullable', 'array', 'max:20'],
            'channel_ids.*' => ['string', 'size:26'],
            'is_enabled' => ['nullable', 'boolean'],
        ] + (($current && ! $request->has('params')) ? [] : self::paramRules((string) $type)));

        $errors = [];
        if (! empty($data['project_id']) && ! Project::query()->whereKey($data['project_id'])->exists()) {
            $errors['project_id'] = [__('observability.alerts.project_unknown')];
        }
        if (! empty($data['region_id']) && ! app(KnativeRegion::class)->newQuery()->whereKey($data['region_id'])->exists()) {
            $errors['region_id'] = [__('observability.uptime.region_unknown')];
        }
        $ids = array_values(array_unique((array) ($data['channel_ids'] ?? [])));
        if ($ids && AlertChannel::query()->whereIn('id', $ids)->count() !== count($ids)) {
            $errors['channel_ids'] = [__('observability.alerts.channel_unknown')];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        if (array_key_exists('channel_ids', $data)) {
            $data['channel_ids'] = $ids;
        }
        if (array_key_exists('params', $data)) {
            $data['params'] = array_intersect_key((array) $data['params'], array_flip(self::paramKeys((string) $type)));
        }
        if (! $current) {
            $data += ['severity' => 'warning', 'cooldown_minutes' => (int) config('observability.alerts.default_cooldown_minutes', 15), 'is_enabled' => true];
        }
        foreach (['severity', 'cooldown_minutes', 'is_enabled'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                unset($data[$k]);
            }
        }

        return $data;
    }

    private static function paramKeys(string $type): array
    {
        return match ($type) {
            'resource_threshold' => ['resource', 'min_level'],
            'error_rate', 'cold_start_rate' => ['threshold', 'window_minutes', 'min_requests'],
            'p95_latency' => ['threshold_ms', 'window_minutes', 'min_requests'],
            default => [],
        };
    }

    private static function paramRules(string $type): array
    {
        return match ($type) {
            'resource_threshold' => [
                'params.resource' => ['nullable', Rule::in(['cpu', 'memory', 'any'])],
                'params.min_level' => ['nullable', Rule::in(['warning', 'critical'])],
            ],
            'error_rate', 'cold_start_rate' => [
                'params.threshold' => ['required', 'numeric', 'min:0', 'max:100'],
                'params.window_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
                'params.min_requests' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            ],
            'p95_latency' => [
                'params.threshold_ms' => ['required', 'numeric', 'min:1', 'max:3600000'],
                'params.window_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
                'params.min_requests' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            ],
            default => [],
        };
    }
}
