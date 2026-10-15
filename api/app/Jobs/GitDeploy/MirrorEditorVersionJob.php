<?php

namespace App\Jobs\GitDeploy;

use App\Models\Git\GitRepoLink;
use App\Models\Git\ProjectGitSettings;
use App\Models\Version;
use App\Services\GitDeploy\GitCommandException;
use App\Services\GitDeploy\ImportException;
use App\Services\GitDeploy\ProjectRemote;
use App\Services\GitDeploy\SourceTree;
use App\Services\GitDeploy\TenantContext;
use App\Services\GitService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * A version saved in the browser editor is committed on top of the deploy branch of the
 * project remote (only the function's files below the root directory change; hidden files,
 * runr.yaml and other directories stay), so `git pull` brings it down and a later push is a
 * fast-forward. Skipped when the version is no longer the latest (a push came after it), when
 * the project deploys from a provider repository, or when the remote was never used.
 */
class MirrorEditorVersionJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [2, 10];

    public function __construct(public string $versionId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('git-mirror:'.$this->versionId))->expireAfter(300)];
    }

    public function handle(ProjectRemote $remote, GitService $git): void
    {
        $version = Version::with(['project', 'creator'])->find($this->versionId);
        $project = $version?->project;

        if (! $version || ! $project || ! $version->commit_id || ! $remote->exists($project)) {
            return;
        }

        if (GitRepoLink::forProject($project->id, TenantContext::currentId())) {
            return;
        }

        $latest = Version::where('project_id', $project->id)->max('number');
        if ((int) $latest !== (int) $version->number) {
            return;
        }

        $settings = ProjectGitSettings::for($project);
        $files = array_filter(
            $git->getAllFilesAtVersion($project, $version->commit_id),
            fn ($content, $file) => SourceTree::isManaged((string) $file),
            ARRAY_FILTER_USE_BOTH,
        );

        if (! $files) {
            return;
        }

        $author = $version->creator
            ? ['name' => (string) ($version->creator->name ?: $version->creator->username), 'email' => (string) ($version->creator->email ?: 'runr@localhost')]
            : null;

        try {
            // Replace what an import of the branch head takes as the function's files: faas
            // its flat file set, xaas its script; everything else in the tree stays
            $head = $remote->resolve($project, $settings->deployRef());
            $replace = array_keys($files);
            if ($head && $project->service_type === 'faas') {
                try {
                    $current = array_filter(array_keys($remote->tree($project, $head, $settings->rootDir())->files), fn ($f) => ! str_contains((string) $f, '/'));
                } catch (ImportException) {
                    $current = [];
                }
                $replace = array_values(array_unique([...$replace, ...$current]));
            }

            $remote->commitFiles(
                $project,
                $settings->deployRef(),
                $files,
                ($version->commit_message ?: "Update {$project->name}")."\n\nSaved in the RunR editor as {$version->label}.",
                $author,
                $settings->rootDir(),
                $head ? $replace : null,
            );
        } catch (GitCommandException $e) {
            // The branch moved (a push in between): try again, the latest-version check decides
            Log::info('Editor version mirror retried', ['version' => $version->id, 'error' => $e->getMessage()]);
            $this->release(2);
        }
    }
}
