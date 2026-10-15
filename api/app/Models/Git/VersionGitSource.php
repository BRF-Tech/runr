<?php

namespace App\Models\Git;

use App\Models\Version;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The git commit a version was created from (a push to the RunR remote, or a provider push /
 * pull request).
 *
 * @property string $id
 * @property string $version_id
 * @property string $project_id
 * @property string $provider
 * @property string $sha
 * @property string|null $ref
 * @property string|null $author_name
 * @property string|null $author_email
 * @property string|null $message
 * @property string|null $url
 * @property int|null $pr_number
 * @property string|null $pushed_by
 */
class VersionGitSource extends Model
{
    use HasUlids;

    protected $table = 'version_git_sources';

    protected $fillable = [
        'version_id', 'project_id', 'provider', 'sha', 'ref', 'author_name', 'author_email',
        'message', 'url', 'pr_number', 'pushed_by',
    ];

    protected $casts = [
        'pr_number' => 'integer',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class)->withoutGlobalScopes();
    }

    public function toPublicArray(): array
    {
        return [
            'provider' => $this->provider,
            'sha' => $this->sha,
            'short_sha' => substr($this->sha, 0, 7),
            'ref' => $this->ref,
            'author_name' => $this->author_name,
            'author_email' => $this->author_email,
            'message' => $this->message,
            'url' => $this->url,
            'pr_number' => $this->pr_number,
        ];
    }
}
