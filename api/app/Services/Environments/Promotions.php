<?php

namespace App\Services\Environments;

use App\Models\Deployment;
use App\Models\EnvironmentPromotion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\User;
use App\Models\Version;
use App\Services\GitDeploy\DeploymentStarter;
use App\Services\GitDeploy\DeployPendingException;
use App\Services\GitDeploy\ImportException;
use App\Support\Rbac\Rbac;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

/**
 * Promotion between environments (docs/environments.md#promotion): the version the source
 * environment runs — the same code version and the same image, never built again — is deployed to
 * the target environment with the target's own configuration.
 *
 *  - preview()  what will change in the target: its code version / image, and the config
 *               differences between what the target runs now and what it will run (the target's
 *               current config: the promotion takes no config along) — names only for env vars,
 *               secrets and bindings, never a value. Also how the source's config differs from
 *               the target's, for information.
 *  - request()  into an environment that requires approval (the primary does by default) the
 *               promotion waits (pending_approval) for someone with environments.approve on the
 *               project who did not request it; otherwise it deploys right away. A newer request
 *               into the same environment supersedes an older one that still waits.
 *  - approve() / reject() / cancel()
 *  - deploy()   DeploymentStarter with the target environment: every deploy check of the target
 *               (region, residency, supply chain, admission and quotas) applies; the promotion
 *               follows its deployment (deploying → deployed | failed, deploymentUpdated()).
 *
 * The approvers hear of a promotion that waits for them, the requester of its approval, rejection,
 * deployment or failure (PromotionNotifier: in-app, mail, a platform event).
 */
class Promotions
{
    public function __construct(private DeploymentStarter $starter) {}

    /**
     * The next environment after $source in the pipeline (null: $source is the last one).
     */
    public function nextAfter(Project $project, ProjectEnvironment $source): ?ProjectEnvironment
    {
        $pipeline = ProjectEnvironment::pipelineOf($project)->values();
        foreach ($pipeline as $i => $environment) {
            if ((string) $environment->getKey() === (string) $source->getKey()) {
                return $pipeline[$i + 1] ?? null;
            }
        }

        return null;
    }

    /**
     * What a promotion from $source to $target changes (see class doc).
     *
     * @return array{source: array<string, mixed>, target: array<string, mixed>, version: array<string, mixed>|null, code: array<string, mixed>, config: list<array<string, mixed>>, environment_differences: list<array<string, mixed>>, requires_approval: bool, can_promote: bool, problem: string|null}
     */
    public function preview(Project $project, ProjectEnvironment $source, ProjectEnvironment $target): array
    {
        $sourceDeployment = $this->liveDeployment($project, $source);
        $targetDeployment = $this->liveDeployment($project, $target);
        $version = $sourceDeployment?->version;

        $problem = match (true) {
            (string) $source->getKey() === (string) $target->getKey() => (string) __('environments.promote_same'),
            $version === null => (string) __('environments.promote_nothing_live', ['environment' => $source->name]),
            default => null,
        };

        // What the target will run: its own config now, with the promoted version's image
        $after = $version !== null ? EnvironmentConfig::snapshot(DeployTarget::environment($project, $target, $version)) : [];
        $before = $targetDeployment !== null && is_array($targetDeployment->config_snapshot)
            ? $targetDeployment->config_snapshot
            : ($targetDeployment !== null ? EnvironmentConfig::snapshot(DeployTarget::project($project, $targetDeployment)) : []);
        $sourceConfig = $sourceDeployment !== null && is_array($sourceDeployment->config_snapshot)
            ? $sourceDeployment->config_snapshot
            : ($sourceDeployment !== null ? EnvironmentConfig::snapshot(DeployTarget::project($project, $sourceDeployment)) : []);

        return [
            'source' => $this->side($source, $sourceDeployment),
            'target' => $this->side($target, $targetDeployment),
            'version' => $version !== null ? self::versionSummary($project, $version) : null,
            'code' => [
                'from' => $targetDeployment?->version !== null ? self::versionSummary($project, $targetDeployment->version) : null,
                'to' => $version !== null ? self::versionSummary($project, $version) : null,
                'changes' => $version !== null && (string) $targetDeployment?->version_id !== (string) $version->id,
            ],
            'config' => $version !== null && $before !== [] ? EnvironmentConfig::diff($before, $after) : [],
            'environment_differences' => $sourceConfig !== [] && $after !== [] ? EnvironmentConfig::diff($sourceConfig, $after) : [],
            'requires_approval' => (bool) $target->requires_approval,
            'can_promote' => $problem === null,
            'problem' => $problem,
        ];
    }

    /**
     * Request a promotion; it deploys at once into an environment without approval.
     *
     * @throws HttpResponseException 422 when there is nothing to promote
     */
    public function request(Project $project, ProjectEnvironment $source, ProjectEnvironment $target, User $user, ?string $message = null): EnvironmentPromotion
    {
        $preview = $this->preview($project, $source, $target);
        if ($preview['problem'] !== null) {
            throw new HttpResponseException(response()->json(['message' => $preview['problem'], 'code' => 'promotion_not_possible', 'errors' => ['source' => [$preview['problem']]]], 422));
        }

        $sourceDeployment = $this->liveDeployment($project, $source);
        $version = $sourceDeployment->version;

        // An older request into the same environment that still waits is replaced by this one
        EnvironmentPromotion::query()->where('project_id', $project->id)
            ->where('target_environment_id', $target->scopeKey())
            ->where('status', EnvironmentPromotion::PENDING_APPROVAL)
            ->update(['status' => EnvironmentPromotion::SUPERSEDED, 'status_message' => 'A newer promotion into the environment was requested', 'updated_at' => now()]);

        $promotion = EnvironmentPromotion::query()->create([
            'project_id' => $project->id,
            'source_environment_id' => $source->scopeKey(),
            'target_environment_id' => $target->scopeKey(),
            'version_id' => $version->id,
            'source_deployment_id' => $sourceDeployment->id,
            'status' => $target->requires_approval ? EnvironmentPromotion::PENDING_APPROVAL : EnvironmentPromotion::APPROVED,
            'image' => self::imageOf($project, $version),
            'diff' => ['code' => $preview['code'], 'config' => $preview['config']],
            'message' => $message !== null ? mb_substr($message, 0, 2000) : null,
            'requested_by' => (string) $user->getKey(),
            'requested_by_name' => (string) ($user->name ?? $user->username ?? ''),
        ]);

        EnvironmentAudit::record('environment.promotion.requested', $project, $target, ['promotion_id' => $promotion->id, 'version' => $version->number, 'from' => $source->name]);
        if ($promotion->status === EnvironmentPromotion::PENDING_APPROVAL) {
            PromotionNotifier::dispatch($promotion, PromotionNotifier::PENDING_APPROVAL);
        }

        if ($promotion->status === EnvironmentPromotion::APPROVED) {
            $this->deploy($promotion, $user);
        }

        return $promotion->refresh();
    }

    /**
     * Approve a waiting promotion and deploy it. The requester cannot approve their own.
     *
     * @throws HttpResponseException
     */
    public function approve(EnvironmentPromotion $promotion, User $user, ?string $comment = null): EnvironmentPromotion
    {
        if ((string) $promotion->requested_by === (string) $user->getKey()) {
            throw new HttpResponseException(response()->json(['message' => __('environments.self_approval'), 'code' => 'self_approval'], 403));
        }

        if (! $promotion->transition([EnvironmentPromotion::PENDING_APPROVAL], [
            'status' => EnvironmentPromotion::APPROVED,
            'decided_by' => (string) $user->getKey(),
            'decided_by_name' => (string) ($user->name ?? $user->username ?? ''),
            'decided_at' => now(),
            'decision_comment' => $comment !== null ? mb_substr($comment, 0, 2000) : null,
        ])) {
            throw $this->notOpen($promotion);
        }
        PromotionNotifier::dispatch($promotion, PromotionNotifier::APPROVED);

        $this->deploy($promotion, $user);

        return $promotion->refresh();
    }

    /** @throws HttpResponseException */
    public function reject(EnvironmentPromotion $promotion, User $user, ?string $comment = null): EnvironmentPromotion
    {
        if (! $promotion->transition([EnvironmentPromotion::PENDING_APPROVAL], [
            'status' => EnvironmentPromotion::REJECTED,
            'decided_by' => (string) $user->getKey(),
            'decided_by_name' => (string) ($user->name ?? $user->username ?? ''),
            'decided_at' => now(),
            'decision_comment' => $comment !== null ? mb_substr($comment, 0, 2000) : null,
        ])) {
            throw $this->notOpen($promotion);
        }
        PromotionNotifier::dispatch($promotion, PromotionNotifier::REJECTED);

        return $promotion;
    }

    /** @throws HttpResponseException */
    public function cancel(EnvironmentPromotion $promotion, User $user): EnvironmentPromotion
    {
        if (! $promotion->transition([EnvironmentPromotion::PENDING_APPROVAL], [
            'status' => EnvironmentPromotion::CANCELLED,
            'status_message' => 'Cancelled by '.((string) ($user->name ?? $user->username ?? $user->getKey())),
        ])) {
            throw $this->notOpen($promotion);
        }

        return $promotion;
    }

    /** Whether $user may approve promotions of the project (environments.approve). */
    public function canApprove(?User $user, Project $project): bool
    {
        return $user !== null && app(Rbac::class)->allows($user, 'environments.approve', $project);
    }

    /**
     * Deploy an approved promotion: the version, the image it was requested with, into the target.
     */
    public function deploy(EnvironmentPromotion $promotion, ?User $user = null): void
    {
        $project = Project::query()->find($promotion->project_id);
        $version = Version::query()->where('project_id', $promotion->project_id)->find($promotion->version_id);
        $target = $promotion->target_environment_id !== null
            ? ProjectEnvironment::query()->find($promotion->target_environment_id)
            : null;

        if (! $project || ! $version || ($promotion->target_environment_id !== null && ! $target)) {
            $this->failed($promotion, 'The project, its version or the environment was deleted.');

            return;
        }

        // The same image as when it was requested: a version built again since is not what was approved
        $image = self::imageOf($project, $version);
        if ($promotion->image !== null && $image !== null && $image !== $promotion->image) {
            $this->failed($promotion, (string) __('environments.promote_image_changed', ['version' => $version->number]));

            return;
        }

        if (! $promotion->transition([EnvironmentPromotion::APPROVED], ['status' => EnvironmentPromotion::DEPLOYING, 'status_message' => null])) {
            return;
        }

        try {
            $deployment = $this->starter->start(
                $project->loadMissing('knativeRegion'),
                $version,
                $promotion->requested_by_name ?: 'promotion',
                $user?->getKey() !== null ? (string) $user->getKey() : $promotion->requested_by,
                environment: $target,
            );
        } catch (ImportException|DeployPendingException $e) {
            $this->failed($promotion, $e->getMessage());

            return;
        }

        $promotion->forceFill(['deployment_id' => $deployment->id])->save();
        EnvironmentAudit::record('environment.promotion.deploying', $project, $target, ['promotion_id' => $promotion->id, 'deployment_id' => $deployment->id]);

        // A demo region (or a synchronous queue) may already have finished the deployment
        $this->deploymentUpdated($deployment->refresh());
    }

    /** Deployment hook: the deployment of a promotion went live or failed. */
    public function deploymentUpdated(Deployment $deployment): void
    {
        if (! ProjectEnvironment::tablesReady()) {
            return;
        }

        $status = match ($deployment->status) {
            'active' => EnvironmentPromotion::DEPLOYED,
            'failed' => EnvironmentPromotion::FAILED,
            'shutting_down', 'shut_down' => EnvironmentPromotion::FAILED,
            default => null,
        };
        if ($status === null) {
            return;
        }

        $promotion = EnvironmentPromotion::query()->where('deployment_id', $deployment->id)->where('status', EnvironmentPromotion::DEPLOYING)->first();
        if (! $promotion) {
            return;
        }

        $message = match ($deployment->status) {
            'active' => null,
            'failed' => $deployment->knative_message ?: 'The deployment failed',
            default => 'Replaced by a newer deploy or stopped before it went live',
        };
        if ($promotion->transition([EnvironmentPromotion::DEPLOYING], ['status' => $status, 'status_message' => $message])) {
            PromotionNotifier::dispatch($promotion, $status === EnvironmentPromotion::DEPLOYED ? PromotionNotifier::DEPLOYED : PromotionNotifier::FAILED);
        }

        $target = $promotion->target_environment_id !== null ? ProjectEnvironment::query()->find($promotion->target_environment_id) : null;
        EnvironmentAudit::system('environment.promotion.'.$status, $promotion->project, $target, [
            'promotion_id' => $promotion->id, 'deployment_id' => $deployment->id, 'version_id' => $promotion->version_id,
        ]);
    }

    /** The environment's live deployment: the active one, else the newest one that starts. */
    public function liveDeployment(Project $project, ProjectEnvironment $environment): ?Deployment
    {
        $live = Deployment::forEnvironment(Deployment::query()->where('project_id', $project->id), $environment->scopeKey())
            ->whereIn('status', Deployment::LIVE)
            ->with('version')
            ->orderByDesc('created_at')
            ->get();

        return $live->firstWhere('status', 'active') ?? $live->first();
    }

    /** The image a version runs: its build image, a CaaS project's configured image of the version. */
    public static function imageOf(Project $project, Version $version): ?string
    {
        return EnvironmentConfig::versionImage($project, $version) ?? ($version->build_image ?: null);
    }

    /** @return array<string, mixed> */
    public static function versionSummary(Project $project, Version $version): array
    {
        return [
            'id' => $version->id,
            'number' => $version->number,
            'label' => $version->label,
            'commit_message' => $version->commit_message,
            'image' => self::imageOf($project, $version),
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function side(ProjectEnvironment $environment, ?Deployment $deployment): array
    {
        return [
            'environment' => $environment->name,
            'environment_id' => $environment->id,
            'region_id' => $environment->regionId(),
            'deployment_id' => $deployment?->id,
            'status' => $deployment?->status,
        ];
    }

    private function failed(EnvironmentPromotion $promotion, string $message): void
    {
        if ($promotion->transition(EnvironmentPromotion::OPEN, ['status' => EnvironmentPromotion::FAILED, 'status_message' => mb_substr($message, 0, 2000)])) {
            PromotionNotifier::dispatch($promotion, PromotionNotifier::FAILED);
        }
        Log::info('Promotion failed', ['promotion' => $promotion->id, 'reason' => $message]);
    }

    private function notOpen(EnvironmentPromotion $promotion): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => __('environments.promotion_not_open', ['status' => $promotion->status]),
            'code' => 'promotion_not_open',
        ], 409));
    }
}
