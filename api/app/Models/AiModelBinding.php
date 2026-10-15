<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project using a model service: at deploy the project's containers get
 * RUNR_MODEL_<ALIAS>_URL / _MODEL / _API / _TASK (/ _OPENAI_BASE) and, with mount_weights, the
 * model's offline snapshot read-only at /models/<alias> (RUNR_MODEL_<ALIAS>_PATH).
 *
 * @property string $id
 * @property string $project_id
 * @property string $ai_model_service_id
 * @property string $alias
 * @property bool $mount_weights
 * @property-read Project $project
 * @property-read AiModelService $service
 */
class AiModelBinding extends Model
{
    use HasUlids;

    /** Env var part of the alias. */
    public const ALIAS_PATTERN = '/^[A-Z][A-Z0-9_]{0,31}$/';

    protected $fillable = ['project_id', 'ai_model_service_id', 'alias', 'mount_weights'];

    protected $casts = [
        'mount_weights' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(AiModelService::class, 'ai_model_service_id');
    }

    /** Alias from a service name: "minilm-l6" → "MINILM_L6". */
    public static function aliasFor(string $name): string
    {
        $alias = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name));
        $alias = trim($alias, '_');
        if ($alias === '' || ! ctype_alpha($alias[0])) {
            $alias = 'M_'.$alias;
        }

        return rtrim(substr($alias, 0, 32), '_');
    }

    /** Mount path of the weights in the project's containers. */
    public function mountPath(): string
    {
        return '/models/'.strtolower($this->alias);
    }
}
