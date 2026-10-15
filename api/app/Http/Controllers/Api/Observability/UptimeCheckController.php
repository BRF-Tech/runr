<?php

namespace App\Http\Controllers\Api\Observability;

use App\Http\Controllers\Api\Controller;
use App\Models\KnativeRegion;
use App\Models\Observability\UptimeCheck;
use App\Models\Observability\UptimeIncident;
use App\Models\Observability\UptimeResult;
use App\Models\Project;
use App\Services\Observability\OutboundUrlGuard;
use App\Services\Observability\Uptime\UptimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Synthetic uptime checks of a project, their results, uptime and incidents.
 *
 * @tags Observability
 */
class UptimeCheckController extends Controller
{
    public function __construct(private UptimeService $uptime, private OutboundUrlGuard $guard) {}

    /** GET /api/projects/{project}/uptime-checks — with uptime 24 h / 7 d / 30 d */
    public function index(Project $project): JsonResponse
    {
        $checks = UptimeCheck::query()->where('project_id', $project->getKey())->orderBy('name')->get();

        return response()->json(['data' => $checks->map(fn (UptimeCheck $c) => $c->toApi() + [
            'uptime_24h' => UptimeService::uptimePercent($c, 24),
            'uptime_7d' => UptimeService::uptimePercent($c, 24 * 7),
            'uptime_30d' => UptimeService::uptimePercent($c, 24 * 30),
            'avg_response_ms_24h' => UptimeService::averageResponseMs($c, 24),
        ])->all()]);
    }

    /** POST /api/projects/{project}/uptime-checks */
    public function store(Request $request, Project $project): JsonResponse
    {
        $max = (int) config('observability.uptime.max_checks_per_project', 20);
        if (UptimeCheck::query()->where('project_id', $project->getKey())->count() >= $max) {
            return response()->json(['message' => __('observability.uptime.too_many', ['max' => $max])], 422);
        }

        $data = $this->validated($request, $project, null);
        $check = UptimeCheck::query()->create($data + [
            'project_id' => $project->getKey(),
            'status' => UptimeCheck::STATUS_UNKNOWN,
            'next_check_at' => now(),
        ]);

        return response()->json(['data' => $check->toApi()], 201);
    }

    /** PUT /api/projects/{project}/uptime-checks/{check} */
    public function update(Request $request, Project $project, string $check): JsonResponse
    {
        $model = $this->find($project, $check);
        $data = $this->validated($request, $project, $model);

        $model->fill($data);
        if ($model->isDirty(['url', 'path', 'method', 'locations', 'region_ids', 'expected_status', 'body_regex'])) {
            // a different probe: the per-location state starts over
            $model->location_state = null;
            $model->next_check_at = now();
        }
        $model->save();

        return response()->json(['data' => $model->toApi()]);
    }

    /** DELETE /api/projects/{project}/uptime-checks/{check} — with results and incidents. */
    public function destroy(Request $request, Project $project, string $check): Response
    {
        $model = $this->find($project, $check);
        UptimeResult::query()->where('uptime_check_id', $model->id)->delete();
        UptimeIncident::query()->where('uptime_check_id', $model->id)->delete();
        $model->delete();

        return response()->noContent();
    }

    /**
     * GET /api/projects/{project}/uptime-checks/{check}/results — recent results (?hours=24,
     * ?location=), uptime and daily bars of the last 30 days.
     */
    public function results(Request $request, Project $project, string $check): JsonResponse
    {
        $request->validate([
            'hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'location' => ['nullable', 'string', 'max:64'],
        ]);
        $model = $this->find($project, $check);
        $hours = (int) $request->input('hours', 24);

        $results = UptimeResult::query()
            ->where('uptime_check_id', $model->id)
            ->where('checked_at', '>=', now()->subHours($hours))
            ->when($request->filled('location'), fn ($q) => $q->where('location', $request->input('location')))
            ->orderByDesc('checked_at')
            ->limit(2000)
            ->get();

        return response()->json(['data' => [
            'check' => $model->toApi(),
            'results' => $results->map(fn (UptimeResult $r) => $r->toApi())->all(),
            'locations' => $results->pluck('location')->unique()->values()->all(),
            'uptime' => [
                '24h' => UptimeService::uptimePercent($model, 24),
                '7d' => UptimeService::uptimePercent($model, 24 * 7),
                '30d' => UptimeService::uptimePercent($model, 24 * 30),
            ],
            'avg_response_ms_24h' => UptimeService::averageResponseMs($model, 24),
            'days' => UptimeService::dailyBars($model, 30),
            'incidents' => $model->incidents()->limit(20)->get()->map(fn (UptimeIncident $i) => $i->toApi())->all(),
        ]]);
    }

    /** POST /api/projects/{project}/uptime-checks/{check}/run — probe now from the control plane. */
    public function run(Project $project, string $check): JsonResponse
    {
        $model = $this->find($project, $check);
        $model->setRelation('project', $project);

        [$url, $error] = $this->uptime->resolveTarget($model);
        if ($url === null) {
            $this->uptime->record($model, UptimeCheck::CONTROL_PLANE, false, null, null, $error);

            return response()->json(['data' => ['ok' => false, 'error' => $error, 'check' => $model->refresh()->toApi()]]);
        }

        $started = microtime(true);
        try {
            $response = Http::timeout(max(1, $model->timeout_seconds))
                ->withOptions(['allow_redirects' => false, 'http_errors' => false])
                ->withUserAgent('runr-uptime/1 (control plane)')
                ->withHeaders($model->headers ?? [])
                ->send($model->method ?: 'GET', $url);
        } catch (\Throwable $e) {
            $response = $e;
        }
        $ms = (int) round((microtime(true) - $started) * 1000);

        [$ok, $status, $error] = UptimeService::evaluate($model, $response);
        $this->uptime->record($model, UptimeCheck::CONTROL_PLANE, $ok, $status, $response instanceof \Throwable ? null : $ms, $error);

        return response()->json(['data' => ['ok' => $ok, 'status_code' => $status, 'response_ms' => $ms, 'error' => $error, 'check' => $model->refresh()->toApi()]]);
    }

    /** GET /api/projects/{project}/uptime-incidents */
    public function incidents(Project $project): JsonResponse
    {
        $incidents = UptimeIncident::query()->where('project_id', $project->getKey())->orderByDesc('started_at')->limit(100)->get();

        return response()->json(['data' => $incidents->map(fn (UptimeIncident $i) => $i->toApi())->all()]);
    }

    /** PATCH /api/projects/{project}/uptime-incidents/{incident} — the public message / title. */
    public function updateIncident(Request $request, Project $project, string $incident): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'message' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $model = UptimeIncident::query()->where('project_id', $project->getKey())->findOrFail($incident);
        $model->fill($data)->save();

        return response()->json(['data' => $model->toApi()]);
    }

    private function validated(Request $request, Project $project, ?UptimeCheck $current): array
    {
        $min = (int) config('observability.uptime.min_interval', 30);
        $max = (int) config('observability.uptime.max_interval', 3600);

        $data = $request->validate([
            'name' => [$current ? 'sometimes' : 'required', 'string', 'max:100'],
            'url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'path' => ['nullable', 'string', 'max:1024', 'regex:/^\//'],
            'method' => ['nullable', Rule::in(['GET', 'HEAD', 'POST'])],
            'headers' => ['nullable', 'array', 'max:20'],
            'headers.*' => ['string', 'max:2000'],
            'interval_seconds' => ['nullable', 'integer', "min:{$min}", "max:{$max}"],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('observability.uptime.max_timeout', 60)],
            'expected_status' => ['nullable', 'string', 'max:64'],
            'body_regex' => ['nullable', 'string', 'max:500'],
            'locations' => ['nullable', Rule::in([UptimeCheck::LOCATION_CONTROL_PLANE, UptimeCheck::LOCATION_REGIONS])],
            'region_ids' => ['nullable', 'array', 'max:20'],
            'region_ids.*' => ['string', 'size:26'],
            'failure_threshold' => ['nullable', 'integer', 'min:1', 'max:20'],
            'recovery_threshold' => ['nullable', 'integer', 'min:1', 'max:20'],
            'is_enabled' => ['nullable', 'boolean'],
            'show_on_status_page' => ['nullable', 'boolean'],
            'status_page_name' => ['nullable', 'string', 'max:100'],
            'status_page_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $errors = [];
        if (isset($data['expected_status']) && $data['expected_status'] !== '' && ! UptimeService::validExpectedStatus($data['expected_status'])) {
            $errors['expected_status'] = [__('observability.uptime.expected_status_invalid')];
        }
        if (! empty($data['body_regex']) && UptimeService::bodyMatches($data['body_regex'], '') === null) {
            $errors['body_regex'] = [__('observability.uptime.invalid_regex')];
        }
        if (! empty($data['url']) && ($reason = $this->guard->check($data['url'], (bool) config('observability.uptime.allow_private_targets', false))) !== null) {
            $errors['url'] = [$reason];
        }
        foreach (array_keys((array) ($data['headers'] ?? [])) as $name) {
            if (! preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,100}$/', (string) $name)) {
                $errors['headers'] = [__('observability.replay.header_invalid')];
            }
        }
        foreach ((array) ($data['region_ids'] ?? []) as $id) {
            if (! app(KnativeRegion::class)->newQuery()->whereKey($id)->exists()) {
                $errors['region_ids'] = [__('observability.uptime.region_unknown')];
            }
        }
        $probe = (new UptimeCheck(['project_id' => $project->getKey()]))->setRelation('project', $project);
        if (! $current && empty($data['url']) && ! $this->uptime->resolveTarget($probe)[0]) {
            $errors['url'] = [__('observability.uptime.no_endpoint')];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        if (array_key_exists('method', $data) && $data['method'] === null) {
            unset($data['method']);
        }
        foreach (['path' => '/', 'expected_status' => '200-399', 'locations' => UptimeCheck::LOCATION_CONTROL_PLANE, 'interval_seconds' => 60, 'timeout_seconds' => 10, 'failure_threshold' => 3, 'recovery_threshold' => 2] as $key => $default) {
            if (array_key_exists($key, $data) && ($data[$key] === null || $data[$key] === '')) {
                $data[$key] = $default;
            }
        }
        if (array_key_exists('headers', $data) && $data['headers'] === null && $current) {
            unset($data['headers']); // null keeps the stored (write-only) headers
        }

        return $data;
    }

    private function find(Project $project, string $id): UptimeCheck
    {
        $check = UptimeCheck::query()->where('project_id', $project->getKey())->findOrFail($id);
        $check->setRelation('project', $project);

        return $check;
    }
}
