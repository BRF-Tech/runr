<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One redeploy of a project onto a runtime patch (RuntimePatchDeployer): "apply patch", a pin,
 * the automatic migration at a deadline or a platform administrator's "force now". It remembers
 * the digest the project ran before, so a migration whose revision does not become ready is rolled
 * back once (RuntimePatchMigrations::watch()), and an automatic one is not tried again for the
 * same patch. Lives next to the project (tenant schema in a multi-tenant install).
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $patch_id
 * @property string $trigger apply | pin | auto | force
 * @property string $status started | succeeded | failed | rolled_back
 * @property string|null $version_id
 * @property string|null $deployment_id
 * @property string|null $queued_deploy_id
 * @property string|null $previous_digest
 * @property string|null $previous_patch_id
 * @property string|null $rollback_deployment_id
 * @property string|null $initiated_by
 * @property string|null $message
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property-read Project $project
 */
class RuntimePatchMigration extends Model
{
    use HasUlids;

    /** The API document's shape of toApi() (the controllers' #[Response] attributes) */
    public const OPENAPI_TYPE = 'array{id: string, patch_id: string|null, trigger: "apply"|"pin"|"auto"|"force", status: "started"|"succeeded"|"failed"|"rolled_back", deployment_id: string|null, queued_deploy_id: string|null, previous_patch_id: string|null, message: string|null, created_at: string|null, finished_at: string|null}';

    public const APPLY = 'apply';

    public const PIN = 'pin';

    public const AUTO = 'auto';

    public const FORCE = 'force';

    public const STARTED = 'started';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'project_id', 'patch_id', 'trigger', 'status', 'version_id', 'deployment_id', 'queued_deploy_id',
        'previous_digest', 'previous_patch_id', 'rollback_deployment_id', 'initiated_by', 'message', 'finished_at',
    ];

    protected $casts = [
        'finished_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Started by RunR at a deadline (or a platform administrator's force): rolled back on failure. */
    public function rollsBack(): bool
    {
        return in_array($this->trigger, [self::AUTO, self::FORCE], true);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'patch_id' => $this->patch_id,
            'trigger' => $this->trigger,
            'status' => $this->status,
            'deployment_id' => $this->deployment_id,
            'queued_deploy_id' => $this->queued_deploy_id,
            'previous_patch_id' => $this->previous_patch_id,
            'message' => $this->message,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
