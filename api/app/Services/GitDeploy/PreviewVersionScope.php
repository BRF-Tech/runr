<?php

namespace App\Services\GitDeploy;

use App\Models\Project;
use App\Models\Version;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Preview versions (builds of pull/merge request commits) are numbered from NUMBER_BASE up and
 * hidden from every regular version query by this global scope on Version: the project's
 * version list, "latest version", the editor, numbering of new versions, registry retention and
 * the deploy endpoint only ever see the project's own line. Git deploy code that works on
 * preview versions queries without the scope.
 */
class PreviewVersionScope implements Scope
{
    public const NUMBER_BASE = 1_000_000_000;

    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('number'), '<', self::NUMBER_BASE);
    }

    public static function isPreview(Version $version): bool
    {
        return (int) $version->number >= self::NUMBER_BASE;
    }

    public static function query(): Builder
    {
        return Version::withoutGlobalScope(self::class);
    }

    /** Number of the next preview version of a project. */
    public static function nextNumber(Project $project): int
    {
        $max = self::query()
            ->where('project_id', $project->id)
            ->where('number', '>=', self::NUMBER_BASE)
            ->max('number');

        return max(self::NUMBER_BASE, (int) $max) + 1;
    }
}
