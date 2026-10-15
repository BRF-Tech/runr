<?php

namespace App\Models;

use App\Services\Bindings\BindingEnv;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A binding attached to a project: its env vars (with env_prefix, e.g. ORDERS_DATABASE_URL)
 * are part of the project's <project>-bindings Secret.
 *
 * @property string $id
 * @property string $binding_id
 * @property string $project_id
 * @property string $env_prefix
 * @property-read Binding $binding
 * @property-read Project $project
 */
class BindingAttachment extends Model
{
    use HasUlids;

    public const PREFIX_PATTERN = '/^[A-Z][A-Z0-9_]*_$/';

    protected $fillable = ['binding_id', 'project_id', 'env_prefix'];

    protected $attributes = ['env_prefix' => ''];

    public function binding(): BelongsTo
    {
        return $this->belongsTo(Binding::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return list<string> env var names this attachment adds to the project */
    public function envNames(): array
    {
        return array_map(fn (string $key) => $this->env_prefix.$key, BindingEnv::keysFor($this->binding));
    }
}
