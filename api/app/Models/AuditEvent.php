<?php

namespace App\Models;

use App\Services\Audit\AuditEventBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One audit log entry (docs/security.md, "Audit log"). Append-only: the application creates
 * rows and never changes or deletes one — save() of an existing row, delete(), and the query
 * builder's update() / delete() all throw. The retention prune (AuditRetention) is the only
 * removal path; on PostgreSQL a trigger enforces the same in the database.
 *
 * Stored in the central schema for both editions (tenant actions carry tenant_id), so the model
 * uses the `central` connection whenever the Tenancy module registered one.
 *
 * @property string $id
 * @property Carbon $occurred_at
 * @property string|null $tenant_id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_name
 * @property string|null $actor_email
 * @property string|null $auth_method
 * @property string|null $impersonator_id
 * @property string|null $impersonator_name
 * @property string $action
 * @property string $result
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string|null $target_name
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property string|null $http_method
 * @property string|null $route
 * @property int|null $status_code
 * @property array|null $metadata
 */
class AuditEvent extends Model
{
    use HasUlids;

    public const RESULT_SUCCESS = 'success';

    public const RESULT_DENIED = 'denied';

    public const RESULT_FAILURE = 'failure';

    public const RESULTS = [self::RESULT_SUCCESS, self::RESULT_DENIED, self::RESULT_FAILURE];

    public const ACTOR_USER = 'user';

    public const ACTOR_API_TOKEN = 'api_token';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_ANONYMOUS = 'anonymous';

    public $timestamps = false;

    /** occurred_at keeps microseconds (timestamp(6)) */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'audit_events';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'metadata' => 'array',
        'status_code' => 'integer',
    ];

    /** Set only while AuditRetention prunes expired rows. */
    private static bool $pruning = false;

    public function getConnectionName()
    {
        return config('database.connections.central') ? 'central' : parent::getConnectionName();
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit events are removed only by the retention prune.'));
    }

    public function newEloquentBuilder($query): AuditEventBuilder
    {
        return new AuditEventBuilder($query);
    }

    public static function isPruning(): bool
    {
        return self::$pruning;
    }

    /** @internal AuditRetention only */
    public static function whilePruning(\Closure $callback): mixed
    {
        self::$pruning = true;
        try {
            return $callback();
        } finally {
            self::$pruning = false;
        }
    }

    /** API / export representation. */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at->toISOString(),
            'tenant_id' => $this->tenant_id,
            'actor' => [
                'type' => $this->actor_type,
                'id' => $this->actor_id,
                'name' => $this->actor_name,
                'email' => $this->actor_email,
                'auth_method' => $this->auth_method,
                'impersonator' => $this->impersonator_id ? [
                    'id' => $this->impersonator_id,
                    'name' => $this->impersonator_name,
                ] : null,
            ],
            'action' => $this->action,
            'result' => $this->result,
            'target' => $this->target_type ? [
                'type' => $this->target_type,
                'id' => $this->target_id,
                'name' => $this->target_name,
            ] : null,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
            'http_method' => $this->http_method,
            'route' => $this->route,
            'status_code' => $this->status_code,
            'metadata' => $this->metadata ?? (object) [],
        ];
    }
}
