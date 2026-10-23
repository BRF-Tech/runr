<?php

namespace App\Models;

use App\Services\Secrets\SecretFingerprint;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A secret environment variable of one environment (docs/environments.md#secrets): over the
 * project's secret of the same name, or one only this environment has.
 *
 * Pass-through like ProjectSecret: RunR writes the value straight into the environment's region —
 * the Kubernetes Secret <workload>-secret-env of the environment's workload
 * (App\Services\Environments\EnvironmentSecrets, through RegionSecretBackend) — and keeps only the
 * metadata here: the name, a version, who changed it when and a keyed fingerprint of the value
 * (SecretFingerprint, never a plain hash). The workload reads it through a secretKeyRef entry.
 *
 * @property string $id
 * @property string $project_id
 * @property string $environment_id
 * @property string $name
 * @property string $source region
 * @property int $version
 * @property string|null $fingerprint
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProjectEnvironment $environment
 */
class EnvironmentSecret extends Model
{
    use HasUlids;

    protected $table = 'environment_secrets';

    protected $fillable = ['project_id', 'environment_id', 'name', 'source', 'version', 'fingerprint', 'updated_by'];

    protected $hidden = ['fingerprint'];

    protected $attributes = [
        'source' => ProjectSecret::SOURCE_REGION,
        'version' => 1,
    ];

    protected $casts = [
        'version' => 'integer',
    ];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class, 'environment_id');
    }

    /** Name + metadata, never the value. */
    public function toPublicArray(): array
    {
        return [
            'name' => $this->name,
            'scope' => 'environment',
            'source' => ProjectSecret::SOURCE_REGION,
            'stored_in' => 'region',
            'version' => (int) ($this->version ?: 1),
            'fingerprint' => SecretFingerprint::short($this->fingerprint),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
