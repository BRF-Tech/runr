<?php

namespace App\Models\Git;

use App\Models\Git\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * A received provider webhook delivery. The (provider_type, delivery_id) pair is unique: a
 * replayed delivery is answered without being processed again.
 *
 * @property int $id
 * @property string $provider_type
 * @property string $delivery_id
 * @property string|null $event
 * @property string|null $git_provider_id
 * @property string|null $git_repo_link_id
 * @property string $status
 * @property string|null $message
 */
class GitWebhookDelivery extends Model
{
    use UsesCentralConnection;

    public const UPDATED_AT = null;

    protected $table = 'git_webhook_deliveries';

    protected $fillable = [
        'provider_type', 'delivery_id', 'event', 'git_provider_id', 'git_repo_link_id', 'status', 'message',
    ];
}
