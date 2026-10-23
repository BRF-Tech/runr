<?php

namespace App\Http\Controllers\Api\Triggers;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\TriggerEvent;
use App\Triggers\Inbox\InboxService;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Stored events of a webhook / platform_event trigger (docs/triggers.md#webhook-inbox): the
 * inbox viewer and replay. Bodies are request data of the sender: `replay.manage`, like captured
 * requests of request replay.
 *
 *   GET  …/triggers/{trigger}/events?status=&per_page=
 *   GET  …/triggers/{trigger}/events/{event}
 *   POST …/triggers/{trigger}/events/{event}/replay
 *   POST …/triggers/{trigger}/events/replay      {status: failed|delivered|all, ids?: [...]} (at most 500)
 */
class TriggerEventController extends Controller
{
    /** Bodies up to this size come back as text too (body_text) when they are UTF-8. */
    private const TEXT_PREVIEW_BYTES = 65536;

    /** The API document's fields of a stored event (TriggerEvent::toSummaryArray()) */
    private const EVENT_FIELDS = 'id: string, trigger_id: string, kind: "webhook"|"platform", type: string|null, subject: string|null, delivery_id: string|null, content_type: string|null, size: int, status: "pending"|"delivering"|"delivered"|"failed", attempts: int, replays: int, last_error: string|null, received_at: string|null, delivered_at: string|null, expires_at: string|null';

    public function __construct(private InboxService $inbox) {}

    #[OpenApiResponse(200, 'A page of the trigger\'s stored events, newest first', type: 'array{data: list<array{'.self::EVENT_FIELDS.'}>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}')]
    public function index(Request $request, Project $project, string $trigger): JsonResponse
    {
        $row = $this->trigger($project, $trigger);
        $request->validate([
            'status' => ['nullable', 'string', Rule::in(TriggerEvent::STATUSES)],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $page = $row->events()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (TriggerEvent $e) => $e->toSummaryArray())->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    #[OpenApiResponse(200, 'The stored event with its headers and body (body_text: the body as text when it is UTF-8 and at most 64 KiB)', type: 'array{data: array{'.self::EVENT_FIELDS.', headers: array<string, mixed>, body_base64: string, body_text: string|null}}')]
    public function show(Project $project, string $trigger, string $event): JsonResponse
    {
        $row = $this->event($this->trigger($project, $trigger), $event);
        $raw = $row->rawBody();
        $text = strlen($raw) <= self::TEXT_PREVIEW_BYTES && mb_check_encoding($raw, 'UTF-8') ? $raw : null;

        return response()->json(['data' => $row->toSummaryArray() + [
            'headers' => (object) ($row->headers ?? []),
            'body_base64' => (string) $row->body,
            'body_text' => $text,
        ]]);
    }

    public function replay(Project $project, string $trigger, string $event): JsonResponse
    {
        $row = $this->event($this->trigger($project, $trigger), $event);

        return response()->json(['data' => $this->inbox->replay($row)->toSummaryArray()]);
    }

    #[OpenApiResponse(200, 'How many events were handed to the event gateway again', type: 'array{replayed: int}')]
    public function replayMany(Request $request, Project $project, string $trigger): JsonResponse
    {
        $row = $this->trigger($project, $trigger);
        $request->validate([
            'status' => 'nullable|string|in:failed,delivered,all',
            'ids' => 'nullable|array|max:500',
            'ids.*' => 'string|size:26',
        ]);

        $events = $row->events()
            ->when($request->filled('ids'), fn ($q) => $q->whereIn('id', (array) $request->input('ids')))
            ->when(! $request->filled('ids') && $request->input('status', 'failed') !== 'all', fn ($q) => $q->where('status', $request->input('status', 'failed')))
            ->whereNotIn('status', [TriggerEvent::PENDING, TriggerEvent::DELIVERING])
            ->orderBy('received_at')
            ->limit(500)
            ->get();

        foreach ($events as $event) {
            $this->inbox->replay($event);
        }

        return response()->json(['replayed' => $events->count()]);
    }

    private function trigger(Project $project, string $trigger): ProjectTrigger
    {
        $row = $project->triggers()
            ->where(fn ($q) => $q->whereKey($trigger)->orWhere('name', $trigger))
            ->firstOrFail();
        abort_unless((bool) $row->handler()?->storesEvents(), 404);

        return $row;
    }

    private function event(ProjectTrigger $trigger, string $event): TriggerEvent
    {
        return $trigger->events()->whereKey($event)->firstOrFail();
    }
}
