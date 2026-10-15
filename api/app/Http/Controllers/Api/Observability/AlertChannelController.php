<?php

namespace App\Http\Controllers\Api\Observability;

use App\Http\Controllers\Api\Controller;
use App\Models\Observability\AlertChannel;
use App\Models\Observability\AlertRule;
use App\Services\Observability\Alerts\AlertManager;
use App\Services\Observability\Alerts\Channels\ChannelRegistry;
use App\Services\Observability\OutboundUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alert channels of the organization. Secrets (webhook URLs, bot tokens, HMAC secrets) are
 * write-only: the API returns a masked summary; on update an omitted config keeps them.
 *
 * @tags Observability
 */
class AlertChannelController extends Controller
{
    public function __construct(
        private ChannelRegistry $registry,
        private AlertManager $alerts,
        private OutboundUrlGuard $guard,
    ) {}

    /** GET /api/observability/alert-channels */
    public function index(): JsonResponse
    {
        return response()->json(['data' => AlertChannel::query()->orderBy('name')->get()->map(fn (AlertChannel $c) => $c->toApi())->all()]);
    }

    /** POST /api/observability/alert-channels — {name, type, config, is_enabled} */
    public function store(Request $request): JsonResponse
    {
        $base = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(AlertChannel::TYPES)],
            'config' => ['required', 'array'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);
        $config = $this->validateConfig($base['type'], (array) $base['config']);

        $channel = AlertChannel::query()->create([
            'name' => $base['name'],
            'type' => $base['type'],
            'config' => $config,
            'is_enabled' => $base['is_enabled'] ?? true,
            'created_by' => $request->user()?->getKey(),
        ]);

        return response()->json(['data' => $channel->toApi()], 201);
    }

    /** PUT /api/observability/alert-channels/{channel} — config optional (omitted: secrets kept) */
    public function update(Request $request, string $channel): JsonResponse
    {
        $model = AlertChannel::query()->findOrFail($channel);
        $base = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'config' => ['sometimes', 'array'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        if (isset($base['config'])) {
            // keys left out of the new config keep their stored (secret) value
            $merged = array_replace((array) $model->config, array_filter((array) $base['config'], fn ($v) => $v !== null && $v !== ''));
            $model->config = $this->validateConfig($model->type, $merged);
        }
        if (isset($base['name'])) {
            $model->name = $base['name'];
        }
        if (isset($base['is_enabled'])) {
            $model->is_enabled = (bool) $base['is_enabled'];
        }
        $model->save();

        return response()->json(['data' => $model->toApi()]);
    }

    /** DELETE /api/observability/alert-channels/{channel} — also removed from every rule. */
    public function destroy(Request $request, string $channel): Response
    {
        $model = AlertChannel::query()->findOrFail($channel);

        foreach (AlertRule::query()->get() as $rule) {
            $ids = array_values(array_diff((array) ($rule->channel_ids ?? []), [$model->id]));
            if ($ids !== array_values((array) ($rule->channel_ids ?? []))) {
                $rule->forceFill(['channel_ids' => $ids])->save();
            }
        }
        $model->delete();

        return response()->noContent();
    }

    /** POST /api/observability/alert-channels/{channel}/test — send a test message now. */
    public function test(Request $request, string $channel): JsonResponse
    {
        $model = AlertChannel::query()->findOrFail($channel);
        $delivery = $this->alerts->sendTest($model);

        return response()->json(['data' => [
            'ok' => $delivery->status === 'sent',
            'response_code' => $delivery->response_code,
            'error' => $delivery->last_error,
            'channel' => $model->refresh()->toApi(),
        ]]);
    }

    /** Type-specific validation of a channel configuration; only known keys are kept. */
    private function validateConfig(string $type, array $config): array
    {
        $rules = $this->registry->for($type)->rules();
        $validated = Validator::make(['config' => $config], $rules)->validate();
        $clean = (array) ($validated['config'] ?? []);

        if ($type === 'email') {
            $clean['emails'] = array_values(array_unique(array_map('strtolower', (array) $clean['emails'])));
        }

        $url = match ($type) {
            'slack', 'teams' => $clean['webhook_url'] ?? null,
            'webhook' => $clean['url'] ?? null,
            default => null,
        };
        if ($url !== null) {
            $reason = $this->guard->check($url, (bool) config('observability.alerts.allow_private_targets', false), $type !== 'webhook');
            if ($reason !== null) {
                throw ValidationException::withMessages(['config.'.($type === 'webhook' ? 'url' : 'webhook_url') => [$reason]]);
            }
        }

        return $clean;
    }
}
