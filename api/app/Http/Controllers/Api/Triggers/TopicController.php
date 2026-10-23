<?php

namespace App\Http\Controllers\Api\Triggers;

use App\Http\Controllers\Controller;
use App\Models\KnativeRegion;
use App\Models\ProjectTrigger;
use App\Models\Topic;
use App\Triggers\Topics\TopicPublisher;
use App\Triggers\Topics\TopicStreams;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The organization's fan-out topics (docs/triggers.md#topics).
 *
 *   GET    /api/topics
 *   POST   /api/topics                 {name, knative_region_id, description?}
 *   GET    /api/topics/{topic}         with its subscriptions (topic triggers)
 *   PATCH  /api/topics/{topic}         {description}
 *   DELETE /api/topics/{topic}         refused (409) while triggers subscribe to it
 *   POST   /api/topics/{topic}/publish {data, content_type?, type?, subject?, id?}
 *
 * {topic} is the topic's id or name. Publishing goes to the region's topic publisher (Redis
 * Streams on the region's Redis); every subscribed function gets the event.
 */
class TopicController extends Controller
{
    /** The API document's fields of a topic (present()) */
    private const TOPIC_FIELDS = 'id: string, name: string, description: string|null, knative_region_id: string, region_name: string|null, stream: string, created_by: string|null, created_at: string|null';

    public function __construct(private TopicPublisher $publisher) {}

    #[OpenApiResponse(200, 'The organization\'s topics with the number of triggers subscribed to each', type: 'array{data: list<array{'.self::TOPIC_FIELDS.', subscriptions: int}>}')]
    public function index(): JsonResponse
    {
        $subscriptions = $this->subscriptionCounts();

        return response()->json([
            'data' => Topic::query()->with('knativeRegion:id,name')->orderBy('name')->get()
                ->map(fn (Topic $t) => $this->present($t) + ['subscriptions' => $subscriptions[$t->name] ?? 0])
                ->values(),
        ]);
    }

    #[BodyParameter('knative_region_id', 'The region (ULID) whose Redis carries the topic', type: 'string')]
    #[OpenApiResponse(201, 'The new topic', type: 'array{data: array{'.self::TOPIC_FIELDS.', subscriptions: int}}')]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:'.Topic::NAME_PATTERN, Rule::unique('topics', 'name')],
            'knative_region_id' => ['required', 'ulid', 'exists:knative_regions,id'],
            'description' => 'nullable|string|max:500',
        ]);

        $region = KnativeRegion::query()->findOrFail($data['knative_region_id']);
        abort_unless($region->has_redis || $region->is_demo, 422, __('triggers.errors.topics_need_redis'));

        $userId = $request->user()?->getAuthIdentifier();
        $topic = Topic::query()->create($data + ['created_by' => $userId !== null ? (string) $userId : null]);

        // The publisher functions and the API publish through (best effort here; deploys retry)
        try {
            $this->publisher->ensure($region);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['data' => $this->present($topic->load('knativeRegion:id,name')) + ['subscriptions' => 0]], 201);
    }

    #[OpenApiResponse(200, 'The topic with the triggers subscribed to it', type: 'array{data: array{'.self::TOPIC_FIELDS.', subscriptions: list<array{trigger_id: string, trigger: string, enabled: bool, project_id: string, project_name: string|null, start_from: string}>}}')]
    public function show(string $topic): JsonResponse
    {
        $row = $this->find($topic);

        return response()->json(['data' => $this->present($row) + [
            'subscriptions' => $row->subscriptions()->load('project:id,name,slug')->map(fn (ProjectTrigger $t) => [
                'trigger_id' => $t->id,
                'trigger' => $t->name,
                'enabled' => (bool) $t->enabled,
                'project_id' => $t->project_id,
                'project_name' => $t->project->name ?? null,
                'start_from' => $t->setting('start_from', 'latest'),
            ])->values(),
        ]]);
    }

    public function update(Request $request, string $topic): JsonResponse
    {
        $row = $this->find($topic);
        $row->update($request->validate(['description' => 'nullable|string|max:500']));

        return response()->json(['data' => $this->present($row)]);
    }

    public function destroy(string $topic): JsonResponse
    {
        $row = $this->find($topic);
        $subscribers = $row->subscriptions()->load('project:id,name');
        if ($subscribers->isNotEmpty()) {
            return response()->json([
                'message' => __('triggers.errors.topic_in_use', ['count' => $subscribers->count()]),
                'subscriptions' => $subscribers->map(fn (ProjectTrigger $t) => [
                    'project_id' => $t->project_id,
                    'project_name' => $t->project->name ?? null,
                    'trigger' => $t->name,
                ])->values(),
            ], 409);
        }

        $this->publisher->delete($row);
        $row->delete();

        return response()->json(null, 204);
    }

    #[BodyParameter('data', 'The event: any JSON value; a string with a content_type other than application/json is sent as it is', required: true, type: 'mixed')]
    #[OpenApiResponse(202, 'The publisher took the event (demo: a demo region, nothing is delivered)', type: 'array{data: array{id: string, stream_id: string, demo?: bool}}')]
    #[OpenApiResponse(502, 'The region\'s publisher did not take the event', type: 'array{message: string}')]
    public function publish(Request $request, string $topic): JsonResponse
    {
        $row = $this->find($topic);
        $request->validate([
            'data' => 'present',
            'content_type' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255',
            'subject' => 'nullable|string|max:255',
            'id' => 'nullable|string|max:255',
        ]);

        $data = $request->input('data');
        $contentType = (string) ($request->input('content_type') ?: 'application/json');
        // A string with a non-JSON content type is sent as is; anything else as JSON
        $body = is_string($data) && $contentType !== 'application/json'
            ? $data
            : (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // the whole region (find() reads only its id and name): its Redis and cluster
        $row->load('knativeRegion');

        try {
            $result = $this->publisher->publish($row, $body, $contentType, $request->input('type'), $request->input('subject'), $request->input('id'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $result], 202);
    }

    private function find(string $topic): Topic
    {
        return Topic::query()->with('knativeRegion:id,name')
            ->where(fn ($q) => $q->whereKey($topic)->orWhere('name', $topic))
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(Topic $topic): array
    {
        return [
            'id' => $topic->id,
            'name' => $topic->name,
            'description' => $topic->description,
            'knative_region_id' => $topic->knative_region_id,
            'region_name' => $topic->knativeRegion?->name,
            'stream' => TopicStreams::key($topic->name),
            'created_by' => $topic->created_by,
            'created_at' => $topic->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, int> topic name => subscribing triggers */
    private function subscriptionCounts(): array
    {
        $counts = [];
        foreach (ProjectTrigger::query()->where('type', 'topic')->get() as $trigger) {
            $name = (string) $trigger->setting('topic');
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return $counts;
    }
}
