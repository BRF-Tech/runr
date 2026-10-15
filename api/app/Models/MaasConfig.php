<?php

namespace App\Models;

use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaasConfig extends Model
{
    use HasUlids;

    protected $fillable = [
        'project_id', 'env_vars', 'timeout_seconds',
        'gpu_type', 'gpu_count',
        'scale_to_zero', 'min_scale', 'max_scale',
    ];

    protected $casts = [
        'env_vars' => Encrypted::class.':array', // secret at rest (docs/security.md)
        'scale_to_zero' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
