<?php

namespace App\Http\Controllers\Api\Observability;

use App\Http\Controllers\Api\Controller;
use App\Models\Observability\LoadTest;
use App\Models\Project;
use App\Services\Observability\LoadTest\LoadTestException;
use App\Services\Observability\LoadTest\LoadTestService;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Load tests of a project (docs/load-test.md): a k6 Job in the project's region against the
 * project's own URL. Test traffic is metered like any other traffic of the project.
 *
 * @tags Observability
 */
class LoadTestController extends Controller
{
    /** The API document's shape of a test (LoadTest::toApi()); results once it completed */
    private const TEST_TYPE = 'array{id: string, project_id: string, deployment_id: string|null, status: "pending"|"running"|"completed"|"failed"|"cancelled", method: "GET"|"POST", host: string, path: string, url: string, has_body: bool, content_type: string|null, users: int, duration_seconds: int, results: array{requests: int, failed_requests: int, rps: float, error_rate: float, latency_ms: array{avg: float|null, min: float|null, p50: float|null, p95: float|null, p99: float|null, max: float|null}, data_received: int, data_sent: int, vus_max: int, test_run_duration_ms: int|null, cold_starts: int, pods_peak: int|null, pods_source: string|null, window: array{from: string, to: string}}|null, error: string|null, started_by: array{id: string, name: string|null}|null, started_at: string|null, finished_at: string|null, created_at: string|null, body: string|null}';

    public function __construct(private LoadTestService $service) {}

    /**
     * GET /api/projects/{project}/load-tests — the latest tests (?limit=, default 10), newest
     * first, and in meta what a new test can choose (users, durations, targets) and the
     * organization's daily limit.
     */
    #[OpenApiResponse(200, 'The latest tests, newest first, and what a new test can choose', type: 'array{data: list<'.self::TEST_TYPE.'>, meta: array{enabled: bool, users: list<int>, durations: list<int>, methods: list<"GET"|"POST">, targets: list<string>, daily_limit: int, used_today: int, max_body_bytes: int, metered: bool}}')]
    public function index(Request $request, Project $project): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        $tests = LoadTest::query()
            ->where('project_id', $project->getKey())
            ->with('starter:id,name')
            ->orderByDesc('created_at')
            ->limit((int) $request->input('limit', 10))
            ->get();

        return response()->json([
            'data' => $tests->map(fn (LoadTest $t) => $t->toApi())->all(),
            'meta' => $this->service->options($project),
        ]);
    }

    /**
     * POST /api/projects/{project}/load-tests — start a test (202): host (default: the function
     * host), path, method GET / POST, body and content_type (POST), users, duration_seconds.
     *
     * @response JsonResponse<array{data: array<string, mixed>}, 202>
     */
    #[OpenApiResponse(202, 'The test started', type: 'array{data: '.self::TEST_TYPE.'}')]
    #[OpenApiResponse(403, 'Load tests are switched off', type: 'array{message: string, code: "load_tests_disabled"}')]
    #[OpenApiResponse(422, 'Not an HTTP function, nothing deployed, or a host the test may not target', type: 'array{message: string, code: "not_http"|"not_deployed"|"host_not_allowed"}|array{message: string, errors: array<string, list<string>>}')]
    #[OpenApiResponse(502, 'The region\'s cluster did not take the test', type: 'array{message: string, code: "cluster_error"}')]
    #[OpenApiResponse(409, 'A test of the project is running, the project is stopped or its region is unavailable', type: 'array{message: string, code: string, load_test_id?: string}')]
    #[OpenApiResponse(429, 'The organization\'s daily limit of load tests is reached', type: 'array{message: string, code: "daily_limit_reached", daily_limit: int}')]
    public function store(Request $request, Project $project): JsonResponse
    {
        $maxBody = max(0, (int) config('observability.load_test.max_body_bytes', 4096));

        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:253'],
            // a path (and query) on the target host: no scheme, no host, no whitespace or fragment
            'path' => ['nullable', 'string', 'max:1024', 'regex:/^\/[^\s#\x00-\x1f\x7f]*$/D'],
            'method' => ['nullable', Rule::in(LoadTest::METHODS)],
            'body' => ['nullable', 'string', 'prohibited_unless:method,POST', function (string $attribute, mixed $value, \Closure $fail) use ($maxBody) {
                if (is_string($value) && strlen($value) > $maxBody) {
                    $fail(__('observability.load_test.body_too_large', ['max' => $maxBody]));
                }
            }],
            'content_type' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+( ?; ?[A-Za-z0-9_-]+=[A-Za-z0-9_.-]+)?$/D'],
            'users' => ['required', 'integer', Rule::in(LoadTestService::userChoices())],
            'duration_seconds' => ['required', 'integer', Rule::in(LoadTestService::durationChoices())],
        ]);

        try {
            $test = $this->service->start($project, $data, $request->user());
        } catch (LoadTestException $e) {
            return response()->json($e->toArray(), $e->status);
        }

        return response()->json(['data' => $test->load('starter:id,name')->toApi()], 202);
    }

    /** GET /api/projects/{project}/load-tests/{loadTest} */
    #[OpenApiResponse(200, 'The test', type: 'array{data: '.self::TEST_TYPE.'}')]
    public function show(Project $project, string $loadTest): JsonResponse
    {
        return response()->json(['data' => $this->find($project, $loadTest)->toApi()]);
    }

    /** POST /api/projects/{project}/load-tests/{loadTest}/cancel — stop a pending or running test. */
    #[OpenApiResponse(200, 'The test', type: 'array{data: '.self::TEST_TYPE.'}')]
    public function cancel(Project $project, string $loadTest): JsonResponse
    {
        $test = $this->find($project, $loadTest);
        $test->setRelation('project', $project);

        return response()->json(['data' => $this->service->cancel($test)->toApi()]);
    }

    private function find(Project $project, string $id): LoadTest
    {
        return LoadTest::query()->where('project_id', $project->getKey())->with('starter:id,name')->findOrFail($id);
    }
}
