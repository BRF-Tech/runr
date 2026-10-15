<?php

namespace App\Workflows\Engine;

use App\Models\Project;
use App\Models\Setting;
use App\Workflows\Models\WorkflowApp;

/**
 * URL the engine calls a project's workflow function at: the app's endpoint override, else the
 * project's function URL https://<slug>.<knative domain> (same derivation as
 * ProjectResource::resolveEndpointUrl for HTTP projects), plus workflows.endpoint_path.
 */
class EndpointResolver
{
    public function resolve(Project $project, ?WorkflowApp $app = null): ?string
    {
        $app ??= WorkflowApp::where('project_id', $project->id)->first();

        if ($app && $app->endpoint_url) {
            return $app->endpoint_url;
        }

        $base = $this->functionUrl($project);
        if ($base === null) {
            return null;
        }

        $path = trim((string) config('workflows.endpoint_path', ''), '/');

        return $path === '' ? $base : $base.'/'.$path;
    }

    public function functionUrl(Project $project): ?string
    {
        $region = $project->knativeRegion;
        $domain = $region?->effective_settings['knative_domain'] ?? null;

        if (! $domain) {
            $baseDomain = Setting::get('base_domain');
            if (! $baseDomain || ! $region) {
                return null;
            }
            $domain = "{$region->name}.{$baseDomain}";
        }

        return "https://{$project->slug}.{$domain}";
    }
}
