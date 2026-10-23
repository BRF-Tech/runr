<?php

namespace App\Models\ApiProduct;

use App\Models\Gateway;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Someone who calls a gateway's API (docs/api-product.md#consumers): a customer of the function
 * owner, identified by its API keys (key routes) or by a JWT claim value (jwt_subject, JWT routes).
 * gateway_id null = a consumer of the organization, accepted on every gateway of it. external_id
 * is the customer number of the system that sells the API and invoices the usage — RunR only
 * measures.
 *
 * @property string $id
 * @property string|null $gateway_id
 * @property string $name
 * @property string|null $email
 * @property string|null $external_id
 * @property string|null $jwt_subject
 * @property string|null $plan_id
 * @property string $status
 * @property array<string, mixed>|null $metadata
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Gateway|null $gateway
 * @property-read ApiPlan|null $plan
 * @property-read Collection<int, ApiKey> $keys
 */
class ApiConsumer extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'api_consumers';

    protected $fillable = [
        'gateway_id', 'name', 'email', 'external_id', 'jwt_subject', 'plan_id', 'status', 'metadata', 'created_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_by' => 'integer',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ApiPlan::class, 'plan_id');
    }

    public function keys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'consumer_id');
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isOrganizationWide(): bool
    {
        return $this->gateway_id === null;
    }
}
