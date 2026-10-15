<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A model of the organization: a Hugging Face Hub repository pinned to a revision (source hub)
 * or files brought into a region without the Hub (upload, pvc, oci). See docs/huggingface.md.
 *
 * @property string $id
 * @property string $name
 * @property string $source
 * @property string|null $hub_id
 * @property string $revision
 * @property string|null $revision_sha
 * @property string|null $task
 * @property string|null $library
 * @property string|null $license
 * @property bool $gated
 * @property bool $private
 * @property int|null $size_bytes
 * @property int|null $parameters
 * @property string|null $card_summary
 * @property array|null $metadata
 * @property string|null $source_ref
 * @property string|null $created_by
 */
class AiModel extends Model
{
    use HasUlids;

    public const SOURCES = ['hub', 'upload', 'pvc', 'oci'];

    /** Model names: DNS-label-like, used in Kubernetes names and paths. */
    public const NAME_PATTERN = '/^[a-z]([-a-z0-9]{0,61}[a-z0-9])?$/';

    protected $fillable = [
        'name', 'source', 'hub_id', 'revision', 'revision_sha', 'task', 'library', 'license',
        'gated', 'private', 'size_bytes', 'parameters', 'card_summary', 'metadata', 'source_ref',
        'created_by',
    ];

    protected $casts = [
        'gated' => 'boolean',
        'private' => 'boolean',
        'size_bytes' => 'integer',
        'parameters' => 'integer',
        'metadata' => 'array',
    ];

    public function snapshots(): HasMany
    {
        return $this->hasMany(AiModelSnapshot::class)->orderBy('created_at');
    }

    public function services(): HasMany
    {
        return $this->hasMany(AiModelService::class);
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(AiModelUpload::class);
    }

    public function isHub(): bool
    {
        return $this->source === 'hub';
    }

    /** The revision a mirror or an online service uses: the resolved commit, else as requested. */
    public function pinnedRevision(): string
    {
        return $this->revision_sha ?: ($this->revision ?: 'main');
    }

    /** Folder name of the model inside the region volume (snapshots/<tenant>/<key>/<revision>). */
    public function storageKey(): string
    {
        return $this->name;
    }

    /** A ready offline copy of this model in a region, if any. */
    public function readySnapshotIn(?string $regionId): ?AiModelSnapshot
    {
        if (! $regionId) {
            return null;
        }

        return $this->snapshots()
            ->where('knative_region_id', $regionId)
            ->where('status', AiModelSnapshot::STATUS_READY)
            ->orderByDesc('completed_at')
            ->first();
    }

    /**
     * A model name derived from a Hub id: "sentence-transformers/all-MiniLM-L6-v2" →
     * "all-minilm-l6-v2" (unique suffix added by the caller when taken).
     */
    public static function nameFromHubId(string $hubId): string
    {
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', basename($hubId)));
        $base = trim($base, '-');
        if ($base === '' || ! ctype_alpha($base[0])) {
            $base = 'm-'.$base;
        }

        return rtrim(substr($base, 0, 40), '-');
    }

    /** $base, or $base-2, $base-3, … when the name is taken. */
    public static function uniqueName(string $base): string
    {
        $name = $base;
        for ($i = 2; static::where('name', $name)->exists(); $i++) {
            $name = rtrim(substr($base, 0, 36), '-').'-'.$i;
        }

        return $name;
    }
}
