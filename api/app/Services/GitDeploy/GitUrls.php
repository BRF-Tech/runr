<?php

namespace App\Services\GitDeploy;

use App\Models\Git\GitProvider;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Setting;

/**
 * Every URL git deploy hands out: the project's remote, console links (commit status targets),
 * webhook and callback endpoints, function URLs of previews.
 */
class GitUrls
{
    public static function console(): string
    {
        return rtrim((string) (config('git-deploy.console_url') ?: config('app.frontend_url') ?: config('app.url')), '/');
    }

    /** Remote URL owner segment: the organization slug (multi-tenant) or the configured owner. */
    public static function owner(): string
    {
        return TenantContext::currentSlug() ?? (string) config('git-deploy.owner', 'runr');
    }

    public static function remote(Project $project): string
    {
        return self::console().'/git/'.self::owner().'/'.$project->slug.'.git';
    }

    public static function project(Project $project, string $tab = 'git'): string
    {
        return self::console()."/projects/{$project->id}".($tab !== '' ? "#{$tab}" : '');
    }

    public static function webhookBase(): string
    {
        return rtrim((string) (config('git-deploy.webhook_base_url') ?: self::console()), '/');
    }

    /** Webhook endpoint of a provider (GitHub App: per provider; GitLab / Forgejo: per link). */
    public static function webhook(string $type, string $id): string
    {
        return self::webhookBase()."/api/git/webhooks/{$type}/{$id}";
    }

    public static function githubSetup(): string
    {
        return self::webhookBase().'/api/git/github/setup';
    }

    public static function gitlabCallback(): string
    {
        return self::webhookBase().'/api/git/oauth/gitlab/callback';
    }

    /** GitHub App installation page with RunR's signed state. */
    public static function githubInstall(GitProvider $provider, string $state): string
    {
        $web = $provider->webUrl();

        return "{$web}/apps/{$provider->app_slug}/installations/new?state=".rawurlencode($state);
    }

    /**
     * Function URL of a Knative Service name in the project's region (the project URL rule:
     * https://<service>.<knative domain>), null without a domain.
     */
    public static function function(Project $project, string $serviceName): ?string
    {
        /** @var KnativeRegion|null $region */
        $region = $project->knativeRegion;
        $domain = $region?->effective_settings['knative_domain'] ?? null;

        if (! $domain) {
            $base = Setting::get('base_domain');
            if (! $base || ! $region) {
                return null;
            }
            $domain = "{$region->name}.{$base}";
        }

        return "https://{$serviceName}.{$domain}";
    }
}
