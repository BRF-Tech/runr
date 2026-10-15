<?php

namespace App\Http\Controllers\Api;

use App\Jobs\GitDeploy\ProcessGitPushJob;
use App\Models\AuditEvent;
use App\Models\Git\GitRepoLink;
use App\Models\Git\ProjectGitSettings;
use App\Models\Project;
use App\Models\User;
use App\Services\GitDeploy\GitAudit;
use App\Services\GitDeploy\GitUrls;
use App\Services\GitDeploy\PktLine;
use App\Services\GitDeploy\ProjectRemote;
use App\Services\GitDeploy\SmartHttpBackend;
use App\Services\GitDeploy\TenantContext;
use App\Support\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * The project git remote: https://<console>/git/<organization>/<project>.git (smart HTTP only).
 *
 *   git remote add runr https://<console>/git/<org>/<project>.git
 *   git push runr main          # user name: anything, password: an API token
 *
 * Authentication is HTTP Basic with an API token as the password (or as the user name with an
 * empty password); clone/fetch need git.pull, push needs git.push — on the project, within the
 * token's scopes (docs/permissions.md). Unknown organizations and projects
 * the user cannot see answer 404, failed sign-ins are rate limited per client IP. A project
 * connected to a provider repository is read-only here. After a push the changed deploy
 * branch is imported (ProcessGitPushJob).
 */
class GitSmartHttpController extends Controller
{
    private const SERVICES = ['git-upload-pack', 'git-receive-pack'];

    public function __construct(
        private ProjectRemote $remote,
        private SmartHttpBackend $backend,
    ) {}

    public function infoRefs(Request $request, string $owner, string $repo): Response
    {
        $service = (string) $request->query('service', '');
        if (! in_array($service, self::SERVICES, true)) {
            return $this->text('RunR remotes speak the smart HTTP protocol only (git 1.6.6 or newer)', 403);
        }

        return $this->serve($request, $owner, $repo, $service, 'info/refs', true);
    }

    public function uploadPack(Request $request, string $owner, string $repo): Response
    {
        return $this->serve($request, $owner, $repo, 'git-upload-pack', 'git-upload-pack', false);
    }

    public function receivePack(Request $request, string $owner, string $repo): Response
    {
        return $this->serve($request, $owner, $repo, 'git-receive-pack', 'git-receive-pack', false);
    }

    private function serve(Request $request, string $owner, string $repo, string $service, string $suffix, bool $advertisement): Response
    {
        if (! config('git-deploy.enabled', true)) {
            return $this->text('Not found', 404);
        }

        @set_time_limit((int) config('git-deploy.backend_timeout', 300));

        $auth = $this->authenticate($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$user, $token] = $auth;

        $tenant = $this->tenant($owner, $user, $token);
        if ($tenant === false) {
            return $this->text('Repository not found', 404);
        }
        $tenantId = $tenant ? (string) $tenant->getTenantKey() : null;
        $push = $service === 'git-receive-pack';

        return TenantContext::run($tenantId, function () use ($request, $repo, $service, $suffix, $advertisement, $user, $token, $tenantId, $push) {
            $name = preg_replace('/\.git$/', '', $repo);
            $project = Project::where('slug', $name)->first() ?? Project::find($name);

            if (! $project || ! $project->isSupportedType()) {
                return $this->text('Repository not found', 404);
            }

            // Permissions of the organization just entered; the token's scopes limit them (rbac)
            $user->withAccessToken($token);
            app(Rbac::class)->flush();
            $ability = $push ? 'git.push' : 'git.pull';

            if (! Gate::forUser($user)->allows('git.pull', $project)) {
                return $this->text('Repository not found', 404);
            }
            if (! Gate::forUser($user)->allows($ability, $project)) {
                if ($push && ! $advertisement) {
                    GitAudit::record('git.push', $project, ['reason' => 'permission'], $user, AuditEvent::RESULT_DENIED);
                }

                return $this->deny($service, $advertisement, $push
                    ? 'You may not push to this project (permission git.push)'
                    : 'You may not read this project (permission git.pull)');
            }

            $link = $push ? GitRepoLink::forProject($project->id, $tenantId) : null;
            if ($link) {
                if (! $advertisement) {
                    GitAudit::record('git.push', $project, ['reason' => 'provider_repository', 'repository' => $link->repo_full_name], $user, AuditEvent::RESULT_DENIED);
                }

                return $this->deny($service, $advertisement, "{$project->name} deploys from {$link->repo_full_name}; push there");
            }

            $settings = ProjectGitSettings::for($project);
            $this->remote->ensure($project, $settings);

            $env = [
                'REMOTE_USER' => (string) ($user->username ?? $user->email ?? 'runr'),
                'RUNR_DEPLOY_BRANCH' => $settings->deploy_branch,
                'RUNR_AUTO_DEPLOY' => $settings->auto_deploy ? '1' : '0',
                'RUNR_PROJECT_URL' => GitUrls::project($project),
            ];
            $config = [
                'http.receivepack' => $push ? 'true' : 'false',
                'receive.denyNonFastForwards' => $settings->allow_force_push ? 'false' : 'true',
                'receive.denyDeletes' => 'false',
                'receive.maxInputSize' => (string) (max(1, (int) config('git-deploy.max_push_mb', 50)) * 1048576),
            ];

            $after = null;
            if ($push && ! $advertisement) {
                $before = $this->remote->heads($project);
                $projectId = $project->id;
                $userId = (string) $user->getKey();
                $after = function () use ($before, $projectId, $userId, $tenantId, $user) {
                    TenantContext::run($tenantId, function () use ($before, $projectId, $userId, $user) {
                        $project = Project::find($projectId);
                        if (! $project) {
                            return;
                        }
                        $settings = ProjectGitSettings::for($project);
                        $now = $this->remote->heads($project);
                        $changed = [];

                        foreach (array_unique([...array_keys($before), ...array_keys($now)]) as $ref) {
                            $old = $before[$ref] ?? ProjectRemote::ZERO;
                            $new = $now[$ref] ?? ProjectRemote::ZERO;
                            if ($old !== $new) {
                                $changed[$ref] = [$old, $new];
                            }
                        }

                        if ($changed) {
                            GitAudit::record('git.push', $project, ['refs' => array_map(fn ($p) => ['before' => $p[0], 'after' => $p[1]], $changed)], $user);
                        }

                        if (isset($changed[$settings->deployRef()])) {
                            [$old, $new] = $changed[$settings->deployRef()];
                            ProcessGitPushJob::dispatch($projectId, $settings->deployRef(), $old, $new, $userId);
                        }
                    });
                };
            }

            return $this->backend->serve(
                $request,
                $this->remote->organizationDir(),
                '/'.$this->remote->repoName($project).'/'.$suffix,
                $env,
                $config,
                $after,
            );
        });
    }

    /**
     * @return array{0: User, 1: PersonalAccessToken}|Response
     */
    private function authenticate(Request $request): array|Response
    {
        $key = 'git-auth:'.$request->ip();
        $limit = max(1, (int) config('git-deploy.auth_attempts_per_minute', 30));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return $this->text('Too many failed sign-ins; try again later', 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }

        [$username, $password] = $this->basicCredentials($request);
        $secret = $password !== '' ? $password : $username;

        if ($secret === '') {
            return $this->challenge();
        }

        /** @var class-string<PersonalAccessToken> $model */
        $model = Sanctum::$personalAccessTokenModel;
        $token = $model::findToken($secret);
        $user = $token?->tokenable;

        $expired = $token && (
            ($token->expires_at && $token->expires_at->isPast())
            || (($minutes = config('sanctum.expiration')) && $token->created_at && $token->created_at->lte(now()->subMinutes((int) $minutes)))
        );

        if (! $token || ! $user instanceof User || $expired) {
            RateLimiter::hit($key, 60);

            return $this->challenge();
        }

        if ($user->is_disabled ?? false) {
            return $this->text(__('api.account.account_disabled'), 403);
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return [$user, $token];
    }

    /** @return array{0: string, 1: string} */
    private function basicCredentials(Request $request): array
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (stripos($header, 'basic ') === 0) {
            $decoded = base64_decode(trim(substr($header, 6)), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$user, $pass] = explode(':', $decoded, 2);

                return [$user, $pass];
            }
        }

        return [(string) $request->getUser(), (string) $request->getPassword()];
    }

    /**
     * The organization of the owner segment: a Tenant (multi-tenant), null (single organization)
     * or false (unknown / not a member / token of another organization).
     */
    private function tenant(string $owner, User $user, PersonalAccessToken $token): mixed
    {
        if (! TenantContext::enabled()) {
            return $owner === (string) config('git-deploy.owner', 'runr') ? null : false;
        }

        $tenant = TenantContext::findBySlug($owner);
        if (! $tenant) {
            return false;
        }

        $tenantId = (string) $tenant->getTenantKey();
        $tokenTenant = $token->getAttribute('tenant_id');
        if ($tokenTenant && $tokenTenant !== $tenantId) {
            return false;
        }

        if (! TenantContext::isPlatformAdmin($user) && TenantContext::role($user, $tenantId) === null) {
            return false;
        }

        return $tenant;
    }

    /** An authenticated request that may not proceed: git shows "remote error: <message>". */
    private function deny(string $service, bool $advertisement, string $message): Response
    {
        if ($advertisement) {
            return new Response(PktLine::advertisementError($service, $message), 200, [
                'Content-Type' => "application/x-{$service}-advertisement",
                'Cache-Control' => 'no-cache',
            ]);
        }

        return $this->text($message, 403);
    }

    private function challenge(): Response
    {
        return $this->text('Authentication required: use an API token as the password', 401, [
            'WWW-Authenticate' => 'Basic realm="RunR git", charset="UTF-8"',
        ]);
    }

    private function text(string $message, int $status, array $headers = []): Response
    {
        return new Response($message."\n", $status, ['Content-Type' => 'text/plain; charset=utf-8'] + $headers);
    }
}
