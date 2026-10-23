<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A project allowed to call another one on the region's internal network
 * (docs/internal-network.md): the callee's allow list. The caller is named by its internal name
 * <project>.<organization> (InternalNetwork::nameOf) — a project of another organization is a
 * cross-organization grant, which the platform can switch off.
 *
 * @property string $id
 * @property string $project_id the callee
 * @property string $caller <project>.<organization>
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
class ProjectInternalCaller extends Model
{
    use HasUlids;

    protected $fillable = ['project_id', 'caller', 'created_by'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
