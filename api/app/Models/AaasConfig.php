<?php

namespace App\Models;

use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AaasConfig extends Model
{
    use HasUlids;

    protected $fillable = [
        'project_id', 'filename', 'handler', 'config_mode', 'blueprint_graph', 'env_vars',
        'memory_mb', 'cpu', 'timeout_seconds',
        'trigger_type', 'schedule', 'queue_stream',
        'event_source_project_id',
        'scale_to_zero', 'min_scale', 'max_scale',
    ];

    protected $casts = [
        'env_vars' => Encrypted::class.':array', // secret at rest (docs/security.md)
        'blueprint_graph' => 'array',
        'scale_to_zero' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function eventSourceProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'event_source_project_id');
    }
}
