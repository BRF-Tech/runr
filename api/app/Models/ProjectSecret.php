<?php

namespace App\Models;

use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A secret environment variable of a project (docs/security.md, "Secret environment variables").
 *
 * The value is encrypted at rest and write-only through the API: it can be replaced, never read
 * back, unless the `secrets.view_values` ability allows it (GET …/secrets/{name}/value). On deploy
 * every secret of the project goes into the Kubernetes Secret <slug>-secret-env in the region's
 * project namespace and reaches the container as a secretKeyRef env entry — never as a plain
 * value in the Knative Service / CronJob spec.
 *
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string $value
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User|null $updater
 */
class ProjectSecret extends Model
{
    use HasUlids;

    /** Most secret variables per project, longest value (bytes). Kubernetes caps a Secret at 1 MiB. */
    public const MAX_PER_PROJECT = 100;

    public const MAX_VALUE_BYTES = 32768;

    protected $fillable = ['project_id', 'name', 'value', 'updated_by'];

    protected $hidden = ['value'];

    protected $casts = [
        'value' => Encrypted::class,
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Name + metadata, never the value. */
    public function toPublicArray(): array
    {
        return [
            'name' => $this->name,
            'updated_at' => $this->updated_at?->toISOString(),
            'updated_by' => $this->relationLoaded('updater') && $this->updater
                ? ['id' => $this->updater->id, 'name' => $this->updater->name]
                : null,
        ];
    }
}
