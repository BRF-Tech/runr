<?php

namespace App\Models;

use App\Casts\Encrypted;
use App\Triggers\TriggerType;
use App\Triggers\TriggerTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One trigger of a project (docs/triggers.md): what invokes the function — HTTP requests, a
 * schedule, a queue, a webhook inbox, a topic, platform events, … (App\Triggers\TriggerTypes).
 *
 * A project has a list of them in `position` order; the first enabled one is the primary trigger
 * that the config row's trigger_type / schedule / queue_stream mirror for API clients of the
 * single-trigger era (App\Triggers\ProjectTriggers::syncLegacyFields). The secrets (a webhook's
 * signing secret, broker credentials) are encrypted at rest and never returned by the API.
 *
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string $type
 * @property array<string, mixed>|null $config
 * @property array<string, string>|null $secrets
 * @property bool $enabled
 * @property int $position
 * @property string|null $ingress_key public key of a webhook inbox URL
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project|null $project
 *
 * @method static Builder<static>|ProjectTrigger query()
 */
class ProjectTrigger extends Model
{
    use HasUlids;

    /** Trigger names: DNS-label like, they name gateway entries, consumer groups and metrics. */
    public const NAME_PATTERN = '/^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?$/';

    /** Most triggers per project. */
    public const MAX_PER_PROJECT = 20;

    protected $fillable = [
        'project_id', 'name', 'type', 'config', 'secrets', 'enabled', 'position', 'ingress_key', 'created_by',
    ];

    protected $hidden = ['secrets'];

    protected $attributes = [
        'enabled' => true,
        'position' => 0,
    ];

    protected $casts = [
        'config' => 'array',
        'secrets' => Encrypted::class.':array',
        'enabled' => 'boolean',
        'position' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<TriggerEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TriggerEvent::class, 'trigger_id');
    }

    /** The registered type; null for a type no longer registered (it then runs as nothing). */
    public function handler(): ?TriggerType
    {
        return app(TriggerTypes::class)->find($this->type);
    }

    /** A config value with its default. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->config ?? [], $key, $default);
    }

    /** A secret value (decrypted), null when unset. */
    public function secret(string $key): ?string
    {
        $value = ($this->secrets ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Whether the row exists (a project without rows gets one synthesized from its config). */
    public function isStored(): bool
    {
        return $this->exists;
    }
}
