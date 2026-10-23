<?php

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Services\Environments\EnvironmentResolver;
use App\Services\Rollouts\DeployStrategies;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A project's deploy strategy (docs/deploy-strategies.md): direct (the default),
 * blue_green or canary, with the options of both. Saved apart from the project config — it
 * changes how the next deploys roll out, not what they run, so it creates no version.
 *
 * `environment` (a name or id; ?environment= on GET, in the body on PUT) is one of the project's
 * environments (docs/deploy-strategies.md#environments): its settings are the project's with what
 * it sets of its own over them (own; inherited when it sets nothing). PUT with environment
 * changes what it sets — environments.manage too — and `reset: true` drops it.
 */
class DeployStrategyController extends Controller
{
    #[OpenApiResponse(200, 'The deploy strategy', type: 'array{data: array{strategy: "direct"|"blue_green"|"canary", blue_green: array{auto_promote: bool, health_path: string|null, health_checks: int, keep_previous_minutes: int}, canary: array{steps: int[], interval_minutes: int, error_rate_threshold: float|null, p95_threshold_ms: int|null}, eligible: bool, trigger_type: string, tracing: bool, defaults: array{strategy: string, blue_green: array<string, mixed>, canary: array<string, mixed>}, environment: string, own: array<string, mixed>|null, inherited: bool, project: string|null}}')]
    public function show(Request $request, Project $project, DeployStrategies $strategies): JsonResponse
    {
        return response()->json(['data' => $strategies->describe($project, EnvironmentResolver::resolve($project, $request->query('environment')))]);
    }

    /**
     * A partial update: strategy and the option keys sent in blue_green / canary; the others keep
     * their value. 422 for an invalid value, or a strategy other than direct on a queue / schedule
     * function.
     */
    #[OpenApiResponse(200, 'The deploy strategy', type: 'array{data: array{strategy: "direct"|"blue_green"|"canary", blue_green: array{auto_promote: bool, health_path: string|null, health_checks: int, keep_previous_minutes: int}, canary: array{steps: int[], interval_minutes: int, error_rate_threshold: float|null, p95_threshold_ms: int|null}, eligible: bool, trigger_type: string, tracing: bool, defaults: array{strategy: string, blue_green: array<string, mixed>, canary: array<string, mixed>}, environment: string, own: array<string, mixed>|null, inherited: bool, project: string|null}}')]
    #[BodyParameter('strategy', 'direct, blue_green or canary (blue-green is accepted too)', type: 'string')]
    #[BodyParameter('blue_green', 'The blue-green options to change', type: 'array{auto_promote?: bool, health_path?: string|null, health_checks?: int, keep_previous_minutes?: int}')]
    #[BodyParameter('canary', 'The canary options to change', type: 'array{steps?: int[], interval_minutes?: int, error_rate_threshold?: float|null, p95_threshold_ms?: int|null}')]
    public function update(Request $request, Project $project, DeployStrategies $strategies): JsonResponse
    {
        $request->validate(['environment' => 'nullable|string|max:64', 'reset' => 'sometimes|boolean']);
        $environment = EnvironmentResolver::resolve($project, $request->input('environment'));

        if ($environment !== null) {
            // An environment's settings are part of the environment
            Gate::authorize('environments.manage', $project);
            $strategies->updateEnvironment($project, $environment, $request->only(['strategy', 'blue_green', 'canary']), $request->boolean('reset'));
        } else {
            $strategies->update($project, $request->only(['strategy', 'blue_green', 'canary']));
        }

        // deleted while the request ran: 404, as for an unknown project
        $fresh = $project->fresh(['knativeRegion', 'xaasConfig', 'faasConfig', 'containerConfig']) ?? throw $project->notFound();

        return response()->json(['data' => $strategies->describe($fresh, $environment?->fresh())]);
    }
}
