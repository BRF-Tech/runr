<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * State of the patch feed (one row per feed reference): what the last check saw, whether the
 * signature verified, and the platform's auto-migration policy.
 *
 * @property string $id
 * @property string $reference
 * @property string $status ok | invalid_signature | invalid_schema | unreachable | disabled
 * @property string|null $error
 * @property string|null $manifest_digest digest of the last good feed
 * @property bool $signature_verified
 * @property Carbon|null $generated_at
 * @property Carbon|null $checked_at
 * @property Carbon|null $fetched_at
 * @property string|null $rejected_digest
 * @property bool|null $auto_migrate
 */
class RuntimePatchFeed extends Model
{
    use HasUlids, UsesCentralConnection;

    /** The API document's shape of toApi() (the controllers' #[Response] attributes) */
    public const OPENAPI_TYPE = 'array{reference: string, enabled: bool, status: "ok"|"invalid_signature"|"invalid_schema"|"unreachable"|"disabled", error: string|null, manifest_digest: string|null, signature_verified: bool, generated_at: string|null, checked_at: string|null, fetched_at: string|null, via_region: string|null, auto_migrate: bool}';

    public const OK = 'ok';

    public const INVALID_SIGNATURE = 'invalid_signature';

    public const INVALID_SCHEMA = 'invalid_schema';

    public const UNREACHABLE = 'unreachable';

    public const DISABLED = 'disabled';

    protected $fillable = [
        'reference', 'status', 'error', 'manifest_digest', 'signature_verified', 'generated_at',
        'checked_at', 'fetched_at', 'rejected_digest', 'auto_migrate',
    ];

    protected $casts = [
        'signature_verified' => 'boolean',
        'generated_at' => 'datetime',
        'checked_at' => 'datetime',
        'fetched_at' => 'datetime',
        'auto_migrate' => 'boolean',
    ];

    /** The row of the configured feed (created on first use). */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['reference' => (string) config('runtime_patches.feed')],
            ['status' => self::DISABLED],
        );
    }

    /** Whether RunR moves projects at a deadline (the platform policy, else the config default). */
    public function autoMigrates(): bool
    {
        return $this->auto_migrate ?? (bool) config('runtime_patches.auto_migrate', true);
    }

    public static function enabled(): bool
    {
        return trim((string) config('runtime_patches.public_key')) !== '' && trim((string) config('runtime_patches.feed')) !== '';
    }

    public function toApi(): array
    {
        return [
            'reference' => $this->reference,
            'enabled' => self::enabled(),
            'status' => self::enabled() ? $this->status : self::DISABLED,
            'error' => $this->error,
            'manifest_digest' => $this->manifest_digest,
            'signature_verified' => $this->signature_verified,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'checked_at' => $this->checked_at?->toIso8601String(),
            'fetched_at' => $this->fetched_at?->toIso8601String(),
            'via_region' => (string) config('runtime_patches.via_region') ?: null,
            'auto_migrate' => $this->autoMigrates(),
        ];
    }
}
