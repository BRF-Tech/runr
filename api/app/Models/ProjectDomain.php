<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer host name attached to a project.
 *
 * status:
 *   pending  — added, ownership not proven yet (TXT record + DNS target checked by "verify")
 *   verified — ownership proven, not routed (e.g. the project is not HTTP-triggered right now)
 *   active   — routed on the region (Traefik IngressRoute in the project namespace)
 *   error    — ownership proven, applying the routing to the region failed (see status_message)
 *
 * tls_mode: none | custom (uploaded PEM, stored only in a Secret on the region) |
 *           cert-manager (Certificate issued by the region's ClusterIssuer into that Secret)
 *
 * @property string $id
 * @property string $project_id
 * @property string $domain
 * @property string $status
 * @property string $verification_token
 * @property string $tls_mode
 * @property string|null $tls_secret_name
 * @property Carbon|null $tls_expires_at
 * @property string|null $status_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
class ProjectDomain extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ERROR = 'error';

    public const TLS_NONE = 'none';

    public const TLS_CUSTOM = 'custom';

    public const TLS_CERT_MANAGER = 'cert-manager';

    /** DNS name of the ownership TXT record: <prefix>.<domain> */
    public const CHALLENGE_PREFIX = '_runr-challenge';

    /** Value prefix of the ownership TXT record: runr-verify=<token> */
    public const CHALLENGE_VALUE_PREFIX = 'runr-verify=';

    protected $fillable = [
        'project_id',
        'domain',
        'status',
        'verification_token',
        'tls_mode',
        'tls_secret_name',
        'tls_expires_at',
        'status_message',
    ];

    protected $hidden = ['verification_token'];

    protected $casts = [
        'tls_expires_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function challengeName(): string
    {
        return self::CHALLENGE_PREFIX.'.'.$this->domain;
    }

    public function challengeValue(): string
    {
        return self::CHALLENGE_VALUE_PREFIX.$this->verification_token;
    }
}
