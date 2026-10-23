<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EnvironmentResource;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectSecret;
use App\Rules\CronSchedule;
use App\Rules\EnvVars;
use App\Rules\KubernetesCpu;
use App\Rules\WithinRegionTimeout;
use App\Services\Environments\EnvironmentManager;
use App\Services\Environments\EnvironmentResolver;
use App\Services\Environments\Promotions;
use App\Services\Helpers\ResourceReservation;
use App\Services\Rollouts\DeployStrategy;
use App\Services\Secrets\SecretBackendException;
use App\Support\Projects\ScaleRules;
use App\Support\Rbac\EnvVisibility;
use App\Support\Secrets\ProjectSecretWriter;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Environments of a project (docs/environments.md): dev, staging, … next to the project's own
 * workload ("production", the primary — always listed, last in the pipeline unless moved). Each
 * runs the project's versions with the project's config and its own overrides, secrets, bindings,
 * custom domains and region. {environment} is a name or an id.
 *
 * Creating, changing and deleting needs environments.manage on the project; overrides with env
 * vars and secrets need env.edit too (they are the project's configuration).
 *
 * @tags Environments
 */
class EnvironmentController extends Controller
{
    /** The API document's shape of the overrides of the body (rules(); saved as a whole) */
    private const OVERRIDES_TYPE = 'array{env_vars?: array<string, string|null>, cpu?: string|null, memory_mb?: int|null, timeout_seconds?: int|null, min_scale?: int|null, max_scale?: int|null, scale_to_zero?: bool|null, schedule?: string|null, queue_stream?: string|null, deploy_strategy?: array{strategy?: string, blue_green?: array<string, mixed>, canary?: array<string, mixed>}|null, scale_schedule?: list<array{cron: string, min_scale: int}>|null}|null';

    /** The API document's shape of an environment's own secret (EnvironmentSecret::toPublicArray()) */
    private const SECRET_TYPE = 'array{name: string, scope: "environment", source: "region", stored_in: "region", version: int, fingerprint: string|null, updated_at: string|null}';

    public function __construct(
        private EnvironmentManager $manager,
        private Promotions $promotions,
    ) {}

    /** The project's environments in pipeline order, each with its live deployment. */
    #[OpenApiResponse(200, 'The environments in pipeline order and what the user may do with them', type: 'array{data: list<'.EnvironmentResource::OPENAPI_TYPE.'>, abilities: array{manage: bool, promote: bool, approve: bool}}')]
    public function index(Request $request, Project $project): JsonResponse
    {
        $data = ProjectEnvironment::pipelineOf($project)
            ->map(fn (ProjectEnvironment $environment) => EnvironmentResource::make($request, $project, $environment, $this->promotions->liveDeployment($project, $environment)))
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
            'abilities' => [
                'manage' => Gate::allows('environments.manage', $project),
                'promote' => Gate::allows('environments.promote', $project),
                'approve' => Gate::allows('environments.approve', $project),
            ],
        ]);
    }

    #[OpenApiResponse(200, 'The environment with its live deployment', type: 'array{data: '.EnvironmentResource::OPENAPI_TYPE.'}')]
    public function show(Request $request, Project $project, string $environment): JsonResponse
    {
        $row = EnvironmentResolver::resolveRow($project, $environment);

        return response()->json(['data' => EnvironmentResource::make($request, $project, $row, $this->promotions->liveDeployment($project, $row))]);
    }

    #[BodyParameter('name', 'Unique in the project', required: true, type: 'string')]
    #[BodyParameter('knative_region_id', 'The region it runs in; default: the project\'s', type: 'string|null')]
    #[BodyParameter('position', 'Place in the pipeline', type: 'int|null')]
    #[BodyParameter('requires_approval', 'A promotion into it waits for an approver', type: 'bool|null')]
    #[BodyParameter('git_branch', 'Pushes to this branch deploy into it', type: 'string|null')]
    #[BodyParameter('auto_deploy', 'Whether a push to git_branch deploys (default true)', type: 'bool|null')]
    #[BodyParameter('inherit_secrets', 'It reads the project\'s secret variables it does not set itself', type: 'bool|null')]
    #[BodyParameter('overrides', 'Its own configuration over the project\'s (env vars need env.edit)', type: self::OVERRIDES_TYPE)]
    #[OpenApiResponse(201, 'The new environment', type: 'array{data: '.EnvironmentResource::OPENAPI_TYPE.'}')]
    public function store(Request $request, Project $project): JsonResponse
    {
        // in a variable: the API document's generator cannot evaluate [...] + rules() (#[BodyParameter])
        $rules = ['name' => ['required', 'string', 'max:'.ProjectEnvironment::MAX_NAME_LENGTH]] + $this->rules($request, $project);
        $data = $request->validate($rules);
        $this->authorizeOverrides($request, $project);

        $environment = $this->manager->create($project, $data, $request->user()?->getKey() !== null ? (string) $request->user()->getKey() : null);

        return response()->json(['data' => EnvironmentResource::make($request, $project, $environment)], 201);
    }

    #[BodyParameter('knative_region_id', 'The region it runs in; null: the project\'s', type: 'string|null')]
    #[BodyParameter('position', 'Place in the pipeline', type: 'int|null')]
    #[BodyParameter('requires_approval', 'A promotion into it waits for an approver', type: 'bool|null')]
    #[BodyParameter('git_branch', 'Pushes to this branch deploy into it', type: 'string|null')]
    #[BodyParameter('auto_deploy', 'Whether a push to git_branch deploys (default true)', type: 'bool|null')]
    #[BodyParameter('inherit_secrets', 'It reads the project\'s secret variables it does not set itself', type: 'bool|null')]
    #[BodyParameter('overrides', 'Its own configuration over the project\'s, saved as a whole (env vars need env.edit; a caller without env.view who sends none keeps them)', type: self::OVERRIDES_TYPE)]
    #[OpenApiResponse(200, 'The environment with its live deployment', type: 'array{data: '.EnvironmentResource::OPENAPI_TYPE.'}')]
    public function update(Request $request, Project $project, string $environment): JsonResponse
    {
        $row = EnvironmentResolver::resolveRow($project, $environment);
        $data = $request->validate($this->rules($request, $project, $row));
        $this->authorizeOverrides($request, $project, $row);

        // Overrides are saved as a whole; a caller who cannot see the environment's variables
        // (no env.view: they are masked in its answers) and sends none keeps them
        if (is_array($data['overrides'] ?? null) && ! array_key_exists('env_vars', $data['overrides'])
            && ! EnvVisibility::visible($request, $project) && array_key_exists('env_vars', $row->overrideValues())) {
            $data['overrides']['env_vars'] = $row->overrideValues()['env_vars'];
        }

        $row = $this->manager->update($row->setRelation('project', $project), $data);

        return response()->json(['data' => EnvironmentResource::make($request, $project, $row, $this->promotions->liveDeployment($project, $row))]);
    }

    public function destroy(Project $project, string $environment): JsonResponse
    {
        $row = EnvironmentResolver::resolveRow($project, $environment);
        $failures = $this->manager->delete($row->setRelation('project', $project));

        return response()->json(['message' => __('environments.deleted', ['name' => $row->name]), 'incomplete' => $failures !== []]);
    }

    /** Secret env vars of the environment: its own, and the project's it inherits (names only). */
    #[OpenApiResponse(200, 'Its own secret variables and the project\'s it inherits (scope project), by name; never their values', type: 'array{data: list<'.self::SECRET_TYPE.'|array{name: string, scope: "project", updated_at: string|null}>, inherit_secrets: bool}')]
    public function secrets(Project $project, string $environment): JsonResponse
    {
        $row = EnvironmentResolver::resolveRow($project, $environment);
        $own = $row->is_primary ? collect() : $row->secrets()->get();
        $inherited = $row->is_primary || $row->inherit_secrets
            ? $project->secrets()->get()->reject(fn (ProjectSecret $s) => $own->contains('name', $s->name))
            : collect();

        return response()->json([
            'data' => $own->map(fn ($s) => $s->toPublicArray())->values()
                ->merge($inherited->map(fn (ProjectSecret $s) => ['name' => $s->name, 'scope' => 'project', 'updated_at' => $s->updated_at?->toISOString()])->values())
                ->sortBy('name')->values(),
            'inherit_secrets' => $row->is_primary || $row->inherit_secrets,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * PUT …/environments/{environment}/secrets/{name} — create or replace (write-only). The value
     * goes straight into the environment's region (<workload>-secret-env); RunR keeps the name, a
     * version and a keyed fingerprint (503 when the region does not answer: nothing changed).
     */
    #[BodyParameter('value', 'The value (write-only)', required: true, type: 'string')]
    #[OpenApiResponse(200, 'The secret variable was replaced', type: 'array{data: '.self::SECRET_TYPE.', requires_deploy: bool}')]
    #[OpenApiResponse(201, 'The secret variable was created', type: 'array{data: '.self::SECRET_TYPE.', requires_deploy: bool}')]
    #[OpenApiResponse(503, 'The region did not answer: nothing changed', type: 'array{message: string, reason: string}')]
    public function setSecret(Request $request, Project $project, string $environment, string $name): JsonResponse
    {
        $row = EnvironmentResolver::resolveRow($project, $environment);
        $row->setRelation('project', $project);

        Validator::make(
            ['name' => $name, 'value' => $request->input('value')],
            [
                'name' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) use ($project) {
                    if ($error = ProjectSecretWriter::nameError((string) $value, ProjectSecretWriter::plainEnv($project))) {
                        $fail($error);
                    }
                }],
                'value' => ['required', 'string', function ($attribute, $value, $fail) use ($row) {
                    if (is_string($value) && strlen($value) > ProjectSecret::MAX_VALUE_BYTES) {
                        $fail(__('api.secret.value_too_large', ['max' => ProjectSecret::MAX_VALUE_BYTES / 1024]));
                    }
                    // Pass-through: the value lives in the environment's region cluster
                    $region = $row->is_primary ? null : $row->region();
                    if (! $row->is_primary && ($region === null || $region->is_demo || (string) $region->kubeconfig === '')) {
                        $fail(__('api.secret.no_cluster', ['region' => $region->name ?? '—']));
                    }
                }],
            ],
        )->validate();

        try {
            $secret = $this->manager->setSecret($row, $name, (string) $request->input('value'), $request->user()?->id);
        } catch (SecretBackendException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status())
                ->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $secret->toPublicArray(), 'requires_deploy' => true], $secret->wasRecentlyCreated ? 201 : 200)
            ->header('Cache-Control', 'no-store');
    }

    public function deleteSecret(Project $project, string $environment, string $name): JsonResponse
    {
        $row = EnvironmentResolver::resolveRow($project, $environment);
        if (! $this->manager->deleteSecret($row->setRelation('project', $project), $name)) {
            abort(404);
        }

        return response()->json(['requires_deploy' => true]);
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, Project $project, ?ProjectEnvironment $environment = null): array
    {
        $regionId = $request->input('knative_region_id') ?: ($environment?->regionId() ?? $project->knative_region_id);

        return $this->strategyRules($project) + $this->warmerRules($request, $project, $environment) + [
            'knative_region_id' => ['nullable', 'string', 'max:26'],
            'position' => ['nullable', 'integer', 'min:0', 'max:'.(ProjectEnvironment::PRIMARY_POSITION + 1000)],
            'requires_approval' => ['nullable', 'boolean'],
            'git_branch' => ['nullable', 'string', 'max:255'],
            'auto_deploy' => ['nullable', 'boolean'],
            'inherit_secrets' => ['nullable', 'boolean'],
            'overrides' => ['nullable', 'array'],
            'overrides.env_vars' => ['nullable', new EnvVars],
            'overrides.cpu' => ['nullable', 'string', new KubernetesCpu],
            'overrides.memory_mb' => ['nullable', 'integer', 'min:64'],
            'overrides.timeout_seconds' => ['nullable', 'integer', 'min:1', new WithinRegionTimeout($regionId ? (string) $regionId : null)],
            'overrides.min_scale' => ['nullable', 'integer', 'min:0', 'max:10'],
            'overrides.max_scale' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'overrides.scale_to_zero' => ['nullable', 'boolean'],
            'overrides.schedule' => ['nullable', 'string', new CronSchedule],
            'overrides.queue_stream' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+$/'],
        ];
    }

    /**
     * overrides.deploy_strategy: the environment's own deploy strategy settings, a partial update
     * of the project's (docs/deploy-strategies.md#environments) — the rules of
     * PUT …/deploy-strategy; a strategy other than direct only for HTTP and workflow functions.
     *
     * @return array<string, mixed>
     */
    private function strategyRules(Project $project): array
    {
        $rules = ['overrides.deploy_strategy' => ['nullable', 'array']];
        foreach (DeployStrategy::rules() as $field => $rule) {
            $rules['overrides.deploy_strategy.'.$field] = $rule;
        }
        $trigger = ResourceReservation::configOf($project)?->trigger_type;
        $rules['overrides.deploy_strategy.strategy'][] = function (string $attribute, mixed $value, \Closure $fail) use ($trigger) {
            if (DeployStrategy::normalizeName($value) !== DeployStrategy::DIRECT && ! DeployStrategy::eligibleTrigger($trigger)) {
                $fail(__('rollouts.not_eligible', ['trigger' => (string) $trigger]));
            }
        };

        return $rules;
    }

    /**
     * overrides.scale_schedule: the environment's own timed warmer (docs/scaling.md#timed-warmer) —
     * rows as on a project (at most max_scale: the one sent, the environment's, else the
     * project's), [] for none; not for a schedule trigger.
     *
     * @return array<string, mixed>
     */
    private function warmerRules(Request $request, Project $project, ?ProjectEnvironment $environment): array
    {
        $config = ResourceReservation::configOf($project);

        return ScaleRules::schedule(
            'overrides.scale_schedule',
            function () use ($request, $environment, $config): int {
                $max = $request->input('overrides.max_scale') ?? ($environment?->overrideValues()['max_scale'] ?? null) ?? $config?->max_scale;

                return is_numeric($max) ? (int) $max : 0;
            },
            fn () => $config?->trigger_type,
        );
    }

    /** Env vars of an environment are configuration: env.edit on the project. */
    private function authorizeOverrides(Request $request, Project $project, ?ProjectEnvironment $environment = null): void
    {
        if (! $request->has('overrides.env_vars')) {
            return;
        }
        // The variables it has, sent back unchanged with other overrides (they are saved as a
        // whole), change nothing
        if ($environment !== null && self::sameEnv($request->input('overrides.env_vars'), $environment->overrideValues()['env_vars'] ?? [])) {
            return;
        }
        Gate::authorize('env.edit', $project);
    }

    /** Whether two env var override maps (name => value, null: removed) are the same. */
    private static function sameEnv(mixed $sent, mixed $stored): bool
    {
        $normalize = function (mixed $vars): array {
            $out = [];
            foreach (is_array($vars) ? $vars : [] as $name => $value) {
                $out[(string) $name] = $value === null ? null : (string) $value;
            }
            ksort($out);

            return $out;
        };

        return $normalize($sent) === $normalize($stored);
    }
}
