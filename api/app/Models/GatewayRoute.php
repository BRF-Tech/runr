<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $gateway_id
 * @property string $project_id
 * @property string $path
 * @property array<array-key, mixed>|null $methods
 * @property bool $strip_prefix
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Gateway $gateway
 * @property-read Project $project
 *
 * @method static Builder<static>|GatewayRoute newModelQuery()
 * @method static Builder<static>|GatewayRoute newQuery()
 * @method static Builder<static>|GatewayRoute query()
 * @method static Builder<static>|GatewayRoute whereCreatedAt($value)
 * @method static Builder<static>|GatewayRoute whereGatewayId($value)
 * @method static Builder<static>|GatewayRoute whereId($value)
 * @method static Builder<static>|GatewayRoute whereMethods($value)
 * @method static Builder<static>|GatewayRoute wherePath($value)
 * @method static Builder<static>|GatewayRoute whereProjectId($value)
 * @method static Builder<static>|GatewayRoute whereSortOrder($value)
 * @method static Builder<static>|GatewayRoute whereStripPrefix($value)
 * @method static Builder<static>|GatewayRoute whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
class GatewayRoute extends Model
{
    use HasUlids;

    protected $fillable = [
        'gateway_id',
        'project_id',
        'path',
        'methods',
        'strip_prefix',
        'sort_order',
    ];

    protected $casts = [
        'methods' => 'array',
        'strip_prefix' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
