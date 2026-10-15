<?php

namespace App\Services\GitDeploy;

use App\Models\Git\PreviewEnvironment;
use App\Models\Project;
use App\Services\GitDeploy\Providers\ProviderClient;

/** Markdown of RunR's comment on a pull/merge request (one per request, updated in place). */
final class PreviewComment
{
    public static function render(Project $project, PreviewEnvironment $preview): string
    {
        $status = match ($preview->status) {
            PreviewEnvironment::PENDING, PreviewEnvironment::BUILDING => 'Building',
            PreviewEnvironment::DEPLOYING => 'Deploying',
            PreviewEnvironment::READY => 'Ready',
            PreviewEnvironment::FAILED => 'Failed',
            PreviewEnvironment::SKIPPED => 'Not deployed',
            PreviewEnvironment::DELETING, PreviewEnvironment::DELETED => 'Deleted',
            default => ucfirst($preview->status),
        };

        $commit = $preview->head_sha ? '`'.substr($preview->head_sha, 0, 7).'`' : '-';
        $url = $preview->status === PreviewEnvironment::READY && $preview->url ? "[{$preview->url}]({$preview->url})" : '-';
        $name = str_replace(['|', '`'], '', $project->name);

        $lines = [
            ProviderClient::commentMarker($project->id),
            "**RunR preview** of `{$name}`",
            '',
            '| Status | Commit | URL |',
            '| --- | --- | --- |',
            "| {$status} | {$commit} | {$url} |",
        ];

        if ($preview->status_message && in_array($preview->status, [PreviewEnvironment::FAILED, PreviewEnvironment::SKIPPED], true)) {
            $lines[] = '';
            $lines[] = '> '.str_replace("\n", ' ', mb_substr($preview->status_message, 0, 500));
        }

        if ($preview->is_fork && $preview->status !== PreviewEnvironment::SKIPPED) {
            $lines[] = '';
            $lines[] = '_This request comes from a fork: secret environment variables are not set in its preview unless the project allows it._';
        }

        $footer = [];
        if ($preview->expires_at && $preview->isLive()) {
            $footer[] = 'Deleted '.$preview->expires_at->utc()->format('Y-m-d H:i').' UTC unless updated';
        }
        $footer[] = '[Open in RunR]('.GitUrls::project($project).')';

        $lines[] = '';
        $lines[] = '<sub>'.implode(' · ', $footer).'</sub>';

        return implode("\n", $lines);
    }
}
