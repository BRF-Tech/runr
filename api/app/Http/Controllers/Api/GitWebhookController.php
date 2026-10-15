<?php

namespace App\Http\Controllers\Api;

use App\Jobs\GitDeploy\ProcessGitEventJob;
use App\Models\AuditEvent;
use App\Models\Git\GitProvider;
use App\Models\Git\GitRepoLink;
use App\Models\Git\GitWebhookDelivery;
use App\Services\GitDeploy\GitAudit;
use App\Services\GitDeploy\Providers\GitEvent;
use App\Services\GitDeploy\Providers\WebhookParser;
use App\Services\GitDeploy\Providers\WebhookSignature;
use App\Services\GitDeploy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Provider webhooks (no session, no API token — the signature is the authentication):
 *   POST /api/git/webhooks/github/{provider}   the GitHub App's webhook (X-Hub-Signature-256)
 *   POST /api/git/webhooks/gitlab/{link}       a GitLab project hook (X-Gitlab-Token)
 *   POST /api/git/webhooks/forgejo/{link}      a Forgejo / Gitea repository hook (HMAC signature)
 * A verified delivery is recorded once (replays answer 200 without effect) and handed to
 * ProcessGitEventJob in the organization's context; the answer is immediate (202).
 */
class GitWebhookController extends Controller
{
    public function github(Request $request, string $provider): JsonResponse
    {
        $app = GitProvider::where('type', GitProvider::GITHUB)->find($provider);
        if (! $app || ! $app->is_enabled || ! config('git-deploy.enabled', true)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $body = (string) $request->getContent();
        if (! WebhookSignature::github($body, $request->header('X-Hub-Signature-256'), $app->webhook_secret)) {
            return $this->rejected(GitProvider::GITHUB, $request, $app->id, null);
        }

        $name = (string) $request->header('X-GitHub-Event', '');
        if ($name === 'ping') {
            return response()->json(['message' => 'pong']);
        }

        $delivery = (string) ($request->header('X-GitHub-Delivery') ?: hash('sha256', $name.$body));
        $event = WebhookParser::parse(GitProvider::GITHUB, $name, json_decode($body, true) ?: []);

        $links = $event->repoId === null ? collect() : GitRepoLink::query()
            ->where('git_provider_id', $app->id)
            ->where('provider_type', GitProvider::GITHUB)
            ->where('repo_id', $event->repoId)
            ->when($event->installationId, fn ($q) => $q->where('installation_id', $event->installationId))
            ->get();

        return $this->accept(GitProvider::GITHUB, $delivery, $name, $event, $links, $app->id);
    }

    public function gitlab(Request $request, string $link): JsonResponse
    {
        $repo = GitRepoLink::where('provider_type', GitProvider::GITLAB)->find($link);
        if (! $repo || ! config('git-deploy.enabled', true)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! WebhookSignature::gitlab($request->header('X-Gitlab-Token'), $repo->webhook_secret)) {
            return $this->rejected(GitProvider::GITLAB, $request, $repo->git_provider_id, $repo->id);
        }

        $body = (string) $request->getContent();
        $name = (string) $request->header('X-Gitlab-Event', '');
        $delivery = (string) ($request->header('X-Gitlab-Event-UUID') ?: hash('sha256', $name.$body));
        $event = WebhookParser::parse(GitProvider::GITLAB, $name, json_decode($body, true) ?: []);

        $links = $event->repoId === $repo->repo_id ? collect([$repo]) : collect();

        return $this->accept(GitProvider::GITLAB, $delivery, $name, $event, $links, $repo->git_provider_id);
    }

    public function forgejo(Request $request, string $link): JsonResponse
    {
        $repo = GitRepoLink::where('provider_type', GitProvider::FORGEJO)->find($link);
        if (! $repo || ! config('git-deploy.enabled', true)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $body = (string) $request->getContent();
        $headers = [];
        foreach (['x-forgejo-signature', 'x-gitea-signature', 'x-hub-signature-256'] as $h) {
            $headers[$h] = $request->header($h);
        }

        if (! WebhookSignature::forgejo($body, $headers, $repo->webhook_secret)) {
            return $this->rejected(GitProvider::FORGEJO, $request, $repo->git_provider_id, $repo->id);
        }

        $name = (string) ($request->header('X-Forgejo-Event') ?: $request->header('X-Gitea-Event', ''));
        $delivery = (string) ($request->header('X-Forgejo-Delivery') ?: $request->header('X-Gitea-Delivery') ?: hash('sha256', $name.$body));
        $event = WebhookParser::parse(GitProvider::FORGEJO, $name, json_decode($body, true) ?: []);

        $links = $event->repoId === $repo->repo_id ? collect([$repo]) : collect();

        return $this->accept(GitProvider::FORGEJO, $delivery, $name, $event, $links, $repo->git_provider_id);
    }

    /**
     * @param  Collection<int, GitRepoLink>  $links
     */
    private function accept(string $type, string $delivery, string $name, GitEvent $event, $links, ?string $providerId): JsonResponse
    {
        $relevant = $event->isPush() || $event->isPullRequest();

        $claimed = GitWebhookDelivery::query()->insertOrIgnore([
            'provider_type' => $type,
            'delivery_id' => mb_substr($delivery, 0, 100),
            'event' => mb_substr($name, 0, 64),
            'git_provider_id' => $providerId,
            'git_repo_link_id' => $links->count() === 1 ? $links->first()->id : null,
            'status' => $relevant && $links->isNotEmpty() ? 'accepted' : 'ignored',
            'message' => $relevant ? ($links->isEmpty() ? 'No project is connected to this repository' : null) : 'Event not used',
            'created_at' => now(),
        ]);

        if ($claimed === 0) {
            return response()->json(['message' => 'Delivery already processed'], 200);
        }

        if (! $relevant || $links->isEmpty()) {
            return response()->json(['message' => 'Ignored'], 202);
        }

        foreach ($links as $link) {
            $link->forceFill(['last_event_at' => now()])->saveQuietly();
            TenantContext::run($link->tenant_id, fn () => ProcessGitEventJob::dispatch($link->id, $event->toArray()));
        }

        return response()->json(['message' => 'Accepted', 'projects' => $links->count()], 202);
    }

    private function rejected(string $type, Request $request, ?string $providerId, ?string $linkId): JsonResponse
    {
        GitAudit::record('git.webhook.rejected', null, [
            'provider_type' => $type,
            'provider_id' => $providerId,
            'link_id' => $linkId,
            'ip' => $request->ip(),
        ], null, AuditEvent::RESULT_DENIED);

        return response()->json(['message' => 'Invalid webhook signature'], 401);
    }
}
