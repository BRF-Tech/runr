<?php

namespace App\Models\Observability;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One probe of an uptime check from one location ("control-plane" or a region name).
 *
 * @property int $id
 * @property string $uptime_check_id
 * @property string $location
 * @property bool $ok
 * @property int|null $status_code
 * @property int|null $response_ms
 * @property string|null $error
 * @property Carbon $checked_at
 */
class UptimeResult extends Model
{
    protected $table = 'uptime_results';

    public $timestamps = false;

    protected $fillable = ['uptime_check_id', 'location', 'ok', 'status_code', 'response_ms', 'error', 'checked_at'];

    protected $casts = [
        'ok' => 'boolean',
        'status_code' => 'integer',
        'response_ms' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function toApi(): array
    {
        return [
            'location' => $this->location,
            'ok' => $this->ok,
            'status_code' => $this->status_code,
            'response_ms' => $this->response_ms,
            'error' => $this->error,
            'checked_at' => $this->checked_at?->toIso8601String(),
        ];
    }
}
