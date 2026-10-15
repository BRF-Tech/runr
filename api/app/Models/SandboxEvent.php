<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Activity log of a sandbox (GET /sandboxes/{id}/logs): lifecycle changes and what was done in it
 * — commands with their exit code and duration, file transfers, terminal sessions. Command output
 * is never stored.
 *
 * @property string $id
 * @property string $sandbox_id
 * @property string $type
 * @property string|null $message
 * @property array|null $data
 * @property string|null $user_id
 * @property Carbon|null $created_at
 */
class SandboxEvent extends Model
{
    use HasUlids, UsesCentralConnection;

    public const UPDATED_AT = null;

    protected $fillable = ['sandbox_id', 'type', 'message', 'data', 'user_id', 'created_at'];

    protected $casts = [
        'data' => 'array',
        'created_at' => 'datetime',
    ];
}
