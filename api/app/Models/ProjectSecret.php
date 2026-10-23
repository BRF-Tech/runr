<?php

namespace App\Models;

use App\Casts\Encrypted;
use App\Services\Secrets\SecretFingerprint;
use App\Support\Secrets\SecretCipher;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A secret environment variable of a project (docs/security.md, "Secret environment variables").
 *
 * RunR keeps its metadata, not its value. By source:
 *  - region   — RunR wrote the value straight into the Kubernetes Secret <slug>-secret-env of the
 *               project's region (App\Services\Secrets\RegionSecretBackend); the row holds the
 *               name, a version, who changed it when and a keyed fingerprint of the value
 *               (SecretFingerprint, never a plain hash). A row written by RunR up to 0.5.10 (or
 *               with config secrets.storage = database) still holds the value encrypted with
 *               APP_KEY until `php artisan secrets:move-to-region` moves it (isHeldInDatabase());
 *               until the upgrade from 0.5.10 is finalized, the row keeps an encrypted copy of
 *               the region's value for a rollback (rollback_copy, hasRollbackCopy(),
 *               App\Services\Secrets\RollbackCopies);
 *  - external — a reference (store + path + key) to a secret store of the organization: External
 *               Secrets Operator syncs it into <slug>-ext-env (ExternalSecretBackend); sync_status
 *               / sync_message / synced_at is what it reported last;
 *  - openbao  — RunR wrote the value into the region's own OpenBao (OpenBaoSecretBackend), the
 *               workload reads it through <slug>-bao-env; metadata as for "region".
 *
 * Every variable reaches the container as a secretKeyRef env entry — never as a plain value in
 * the Knative Service / CronJob spec. Reading a value back takes the `secrets.view_values`
 * ability (GET …/secrets/{name}/value) and reads it from the region.
 *
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string|null $value rows not moved to the region yet, and the copies kept for a rollback
 * @property bool $rollback_copy the value is the region's, kept encrypted for a rollback to 0.5.10
 * @property string $source region | external | openbao
 * @property int $version
 * @property string|null $fingerprint
 * @property string|null $secret_store_id
 * @property string|null $remote_path
 * @property string|null $remote_key
 * @property string|null $sync_status pending | synced | failed
 * @property string|null $sync_message
 * @property Carbon|null $synced_at
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User|null $updater
 * @property-read SecretStore|null $store
 */
class ProjectSecret extends Model
{
    use HasUlids;

    /** Most secret variables per project, longest value (bytes). Kubernetes caps a Secret at 1 MiB. */
    public const MAX_PER_PROJECT = 100;

    public const MAX_VALUE_BYTES = 32768;

    /** A value RunR wrote into the project's region. */
    public const SOURCE_REGION = 'region';

    /** A reference to a secret store of the organization. */
    public const SOURCE_EXTERNAL = 'external';

    /** A value RunR wrote into the region's own OpenBao. */
    public const SOURCE_OPENBAO = 'openbao';

    public const SYNC_PENDING = 'pending';

    public const SYNC_SYNCED = 'synced';

    public const SYNC_FAILED = 'failed';

    protected $fillable = [
        'project_id', 'name', 'value', 'updated_by',
        'source', 'version', 'fingerprint', 'rollback_copy',
        'secret_store_id', 'remote_path', 'remote_key',
        'sync_status', 'sync_message', 'synced_at',
    ];

    protected $hidden = ['value', 'fingerprint'];

    protected $attributes = [
        'source' => self::SOURCE_REGION,
        'version' => 1,
        'rollback_copy' => false,
    ];

    protected $casts = [
        'value' => Encrypted::class,
        'version' => 'integer',
        'rollback_copy' => 'boolean',
        'synced_at' => 'datetime',
    ];

    /** The raw value hasRollbackCopy() last checked, and what it found. */
    private ?string $copyCheckedFor = null;

    private bool $copyMatches = false;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(SecretStore::class, 'secret_store_id');
    }

    public function isExternal(): bool
    {
        return $this->source === self::SOURCE_EXTERNAL;
    }

    /**
     * The value is still in RunR's database (written before pass-through, or with
     * secrets.storage = database) — not merely the copy kept for a rollback.
     */
    public function isHeldInDatabase(): bool
    {
        if ($this->isExternal() || $this->getRawOriginal('value') === null) {
            return false;
        }

        return ! $this->hasRollbackCopy();
    }

    /**
     * The database value is the encrypted copy of the region's value kept for a rollback to 0.5.10
     * (RollbackCopies): RunR reads the value from the region, 0.5.10 would read the copy.
     *
     * A copy RunR 0.5.10 changed after a rollback — it writes the value, never the fingerprint —
     * no longer matches the fingerprint: the database holds the newer value again
     * (isHeldInDatabase()), and the next deploy or `secrets:move-to-region` writes it into the
     * region. A copy no key of this installation decrypts stays a copy: 0.5.10 could not read it
     * either, the region holds the value.
     */
    public function hasRollbackCopy(): bool
    {
        $raw = $this->getRawOriginal('value');
        if ($this->isExternal() || ! $this->rollback_copy || $raw === null) {
            return false;
        }
        if (! $this->fingerprint) {
            return false;
        }

        $raw = (string) $raw;
        if ($this->copyCheckedFor !== $raw) {
            $this->copyCheckedFor = $raw;
            try {
                $this->copyMatches = SecretFingerprint::matches(SecretCipher::decrypt($raw), $this->fingerprint);
            } catch (\Throwable) {
                $this->copyMatches = true;
            }
        }

        return $this->copyMatches;
    }

    /** Name + metadata, never the value. */
    public function toPublicArray(): array
    {
        $data = [
            'name' => $this->name,
            'source' => $this->source ?: self::SOURCE_REGION,
            'version' => (int) ($this->version ?: 1),
            'updated_at' => $this->updated_at?->toISOString(),
            'updated_by' => $this->relationLoaded('updater') && $this->updater
                ? ['id' => $this->updater->id, 'name' => $this->updater->name]
                : null,
        ];

        if ($this->isExternal()) {
            $store = $this->relationLoaded('store') ? $this->store : null;
            $data['reference'] = [
                'store_id' => $this->secret_store_id,
                'store_name' => $store?->name,
                'provider' => $store?->provider,
                'path' => $this->remote_path,
                'key' => $this->remote_key,
            ];
            $data['sync'] = [
                'status' => $this->sync_status ?? self::SYNC_PENDING,
                'message' => $this->sync_message,
                'synced_at' => $this->synced_at?->toISOString(),
            ];
        } else {
            // Where the value is: the region's Secret, its OpenBao, or still the database (not moved yet)
            $data['stored_in'] = match (true) {
                $this->isHeldInDatabase() => 'database',
                $this->source === self::SOURCE_OPENBAO => 'openbao',
                default => 'region',
            };
            $data['fingerprint'] = SecretFingerprint::short($this->fingerprint);
            // An encrypted copy is kept in RunR's database for a rollback to 0.5.10 (RollbackCopies)
            $data['rollback_copy'] = $this->hasRollbackCopy();
        }

        return $data;
    }
}
