<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Billing\Models\BillingEvent;
use Modules\Billing\Services\BillingEvents;
use Modules\Billing\Services\BillingRefusal;
use Modules\Billing\Services\BillingSettings;

/**
 * The organization's billing event log (docs/billing.md#webhook-events) — the same payloads its
 * webhook receives:
 *
 *  GET  /api/billing/events                     ?order=asc|desc &after= &before=<event id> &type= &limit=
 *  POST /api/billing/events/test                record and deliver a billing.test event
 *  POST /api/billing/events/{event}/redeliver   deliver an event again
 */
class EventController extends Controller
{
    public function __construct(
        private readonly BillingEvents $events,
        private readonly BillingSettings $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'order' => ['sometimes', Rule::in(['asc', 'desc'])],
            'after' => ['sometimes', 'string', 'max:26'],
            'before' => ['sometimes', 'string', 'max:26'],
            'type' => ['sometimes', Rule::in(BillingEvents::TYPES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        // Event ids are ULIDs: their order is the order they were recorded in
        $desc = $request->query('order') === 'desc';
        $limit = (int) $request->query('limit', '100');
        $query = BillingEvent::query()->orderBy('id', $desc ? 'desc' : 'asc')
            ->when($request->filled('after'), fn ($q) => $q->where('id', '>', (string) $request->query('after')))
            ->when($request->filled('before'), fn ($q) => $q->where('id', '<', (string) $request->query('before')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', (string) $request->query('type')));

        $events = $query->limit($limit + 1)->get();
        $more = $events->count() > $limit;
        $events = $events->take($limit);

        return response()->json([
            'data' => $events->map(fn (BillingEvent $e) => $e->toApiArray())->values(),
            'meta' => [
                'next_after' => $more && ! $desc ? $events->last()?->id : null,
                'next_before' => $more && $desc ? $events->last()?->id : null,
                'webhook_configured' => $this->settings->webhookUrl() !== '',
            ],
        ]);
    }

    public function redeliver(string $event): JsonResponse
    {
        $this->assertWebhook();

        $model = $this->events->redeliver(BillingEvent::query()->findOrFail($event));

        return response()->json(['data' => $model->fresh()?->toApiArray()]);
    }

    public function test(): JsonResponse
    {
        $this->assertWebhook();

        $event = $this->events->record(BillingEvents::TEST, ['message' => 'Test event from RunR']);
        // Delivered right away (unless the queue already did) — the answer shows the result
        $event = $event?->fresh();
        if ($event && $event->delivery_status === BillingEvent::PENDING) {
            $this->events->deliver($event);
        }
        $event = $event?->fresh();

        return response()->json([
            'data' => $event?->toApiArray(),
            'delivered' => $event ? $event->delivery_status === BillingEvent::DELIVERED : null,
        ]);
    }

    private function assertWebhook(): void
    {
        if ($this->settings->webhookUrl() === '') {
            throw new BillingRefusal('webhook_not_configured', __('billing.webhook_not_configured'), 409);
        }
    }
}
