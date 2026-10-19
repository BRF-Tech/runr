<?php

namespace App\Support\Projects;

use App\Models\Project;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * What decides whether a project may be deployed and how large it may get, beyond the region
 * capacity (WithinResourceLimit):
 *
 *  - a stopped project (ProjectStopService) is never deployed until it is resumed — core, also
 *    with billing off; production deploys, previews, resumes, region migrations and runtime test
 *    deploys all ask here;
 *  - deploy guards and config limiters that optional modules register: the Billing module refuses
 *    a project in a fixed-price region without an approved allocation and keeps everything the
 *    project can run there — its worst case up to max_scale, with a deploy's overrides, and its
 *    previews — within the approved resources (docs/billing.md#what-a-fixed-project-may-use).
 *
 * Guards refuse by throwing (an HttpResponseException with a JSON body {message, code}) and get
 * the DeployIntent (the deploy's overrides, or the preview being deployed); config limiters get a
 * requested config change of an existing project (merged over its stored config by the caller's
 * layers, see WorkloadSizing::of) and return the refusal {code, message}, null when it fits. Every
 * refusal reaches the client with its code: a deploy, preview redeploy or runtime test deploy as
 * the guard's response, a config change as 422 {message, code, errors} (assertConfigFits), a
 * resume as redeploy_error / redeploy_code, a git deploy as the ImportException's errorCode.
 */
final class ProjectAdmission
{
    /** @var list<Closure(Project, DeployIntent): void> */
    private static array $deployGuards = [];

    /** @var list<Closure(Project, ?string, array<string, mixed>): (array{code: string, message: string}|string|null)> */
    private static array $configLimiters = [];

    /** @param  Closure(Project, DeployIntent): void  $guard  throws to refuse the deploy */
    public static function guardDeploys(Closure $guard): void
    {
        self::$deployGuards[] = $guard;
    }

    /**
     * A limiter gets the project, the region it will be in and the requested config fields (cpu,
     * memory_mb, trigger_type, min_scale, max_scale, scale_to_zero, gpu — only what the request
     * changes); it returns the refusal {code, message} (a plain message counts as code
     * resource_limit_exceeded), or null when the changed project fits.
     *
     * @param  Closure(Project, ?string, array<string, mixed>): (array{code: string, message: string}|string|null)  $limiter
     */
    public static function limitConfigs(Closure $limiter): void
    {
        self::$configLimiters[] = $limiter;
    }

    /**
     * Refuse the deploy of a stopped project (409 project_stopped), then ask every guard.
     *
     * @throws HttpResponseException
     */
    public static function assertDeployable(Project $project, ?DeployIntent $intent = null): void
    {
        if ($project->isStopped()) {
            throw new HttpResponseException(response()->json([
                'message' => __('api.project.stopped', ['reason' => (string) $project->stop_reason]),
                'code' => 'project_stopped',
                'reason' => $project->stop_reason,
                'level' => $project->getAttribute('stop_level'),
            ], 409));
        }

        $intent ??= new DeployIntent;
        foreach (self::$deployGuards as $guard) {
            $guard($project, $intent);
        }
    }

    /**
     * The message of the first limiter that refuses a config change of the project, null when
     * every limiter admits it.
     *
     * @param  array<string, mixed>  $config  the requested config fields
     */
    public static function configProblem(Project $project, ?string $regionId, array $config): ?string
    {
        return self::configRefusal($project, $regionId, $config)['message'] ?? null;
    }

    /**
     * The first limiter's refusal of a config change — its code and message, the shape of a refused
     * deploy — null when every limiter admits it.
     *
     * @param  array<string, mixed>  $config  the requested config fields
     * @return array{code: string, message: string}|null
     */
    public static function configRefusal(Project $project, ?string $regionId, array $config): ?array
    {
        foreach (self::$configLimiters as $limiter) {
            $refusal = $limiter($project, $regionId, $config);
            if (is_array($refusal)) {
                return $refusal;
            }
            if (is_string($refusal) && $refusal !== '') {
                return ['code' => 'resource_limit_exceeded', 'message' => $refusal];
            }
        }

        return null;
    }

    /**
     * Message of a refusal thrown by assertDeployable() (for callers that report instead of
     * answering the request, e.g. the git deploy).
     */
    public static function messageOf(HttpResponseException $e): string
    {
        $data = json_decode((string) $e->getResponse()->getContent(), true);

        return is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : __('api.project.not_deployable');
    }

    /** Code of a refusal thrown by assertDeployable() (project_stopped, fixed_allocation_exceeded, …). */
    public static function codeOf(HttpResponseException $e): ?string
    {
        $data = json_decode((string) $e->getResponse()->getContent(), true);

        return is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : null;
    }

    /**
     * A config change refused by a limiter, answered like a refused deploy — 422 with the
     * limiter's code and message — and as the validation error of $attribute.
     *
     * @param  array<string, mixed>  $config  the requested config fields
     *
     * @throws HttpResponseException
     */
    public static function assertConfigFits(Project $project, ?string $regionId, array $config, string $attribute = 'config'): void
    {
        $refusal = self::configRefusal($project, $regionId, $config);
        if ($refusal === null) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => $refusal['message'],
            'code' => $refusal['code'],
            'errors' => [$attribute => [$refusal['message']]],
        ], 422));
    }

    /** Whether the project may be deployed now; the refusal message, null when it may. */
    public static function refusal(Project $project, ?DeployIntent $intent = null): ?string
    {
        try {
            self::assertDeployable($project, $intent);
        } catch (HttpResponseException $e) {
            return self::messageOf($e);
        }

        return null;
    }

    /** Test helper: forget every guard and limiter. */
    public static function reset(): void
    {
        self::$deployGuards = [];
        self::$configLimiters = [];
    }
}
