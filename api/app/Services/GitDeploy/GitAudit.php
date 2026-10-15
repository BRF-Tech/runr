<?php

namespace App\Services\GitDeploy;

use App\Events\GitDeployAudited;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;

/**
 * Git deploy actions as domain events (GitDeployAudited) plus an application log line — never
 * with token or secret values. The audit log (docs/security.md) records the console API requests
 * through App\Services\Audit\AuditCatalog:
 *   git.settings.update   PUT    /api/projects/{project}/git
 *   git.redeploy          POST   /api/projects/{project}/git/redeploy
 *   git.repo.connect      POST   /api/projects/{project}/git/connect
 *   git.repo.disconnect   DELETE /api/projects/{project}/git/connect
 *   git.preview.redeploy  POST   /api/projects/{project}/previews/{preview}/redeploy
 *   git.preview.delete    DELETE /api/projects/{project}/previews/{preview}
 *   git_provider.create / git_provider.update / git_provider.delete   /api/git-providers
 * Actions without a console API request are written to the audit log here (EXPLICIT, listed in
 * AuditCatalog::EXPLICIT_ACTIONS):
 *   git.push               refs updated on a project remote (before/after per ref); a push the
 *                          user may not make is recorded with result "denied"
 *   git.installation.link  GitHub App installation linked to the organization (setup callback)
 *   git.webhook.rejected   a webhook with a bad signature / token (no actor)
 */
final class GitAudit
{
    public const EXPLICIT = ['git.push', 'git.installation.link', 'git.webhook.rejected'];

    /**
     * @param  Authenticatable|string|null  $actor  the user (or user id); null: the request's user
     */
    public static function record(
        string $action,
        ?Project $project = null,
        array $data = [],
        Authenticatable|string|null $actor = null,
        string $result = AuditEvent::RESULT_SUCCESS,
    ): void {
        $actorId = $actor instanceof Authenticatable
            ? (string) $actor->getAuthIdentifier()
            : ($actor ?? (auth()->id() !== null ? (string) auth()->id() : null));
        $tenantId = TenantContext::currentId();

        Log::info('git deploy: '.$action, [
            'actor_id' => $actorId,
            'tenant_id' => $tenantId,
            'project_id' => $project?->id,
            'result' => $result,
            'data' => $data,
        ]);

        try {
            event(new GitDeployAudited($action, $actorId, $tenantId, $project?->id, $data));
        } catch (\Throwable $e) {
            Log::warning('Git audit event not delivered', ['action' => $action, 'error' => $e->getMessage()]);
        }

        if (! in_array($action, self::EXPLICIT, true)) {
            return;
        }

        $user = $actor instanceof Authenticatable ? $actor : ($actorId !== null ? User::find($actorId) : null);

        app(AuditLogger::class)->record($action, array_filter([
            'actor' => $user,
            'tenant_id' => $tenantId,
            'result' => $result,
            'target' => $project,
            'metadata' => $data,
        ], fn ($value) => $value !== null) + ['tenant_id' => $tenantId]);
    }
}
