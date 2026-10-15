<?php

namespace App\Models;

use App\Casts\Encrypted;
use App\Services\SupplyChain\SupplyChainDb;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Supply-chain settings of one region (docs/supply-chain.md): SBOM, vulnerability scan, scanner
 * DB mirror, policy gate, signing key, admission policy and the region's data location.
 * Platform-wide (central schema); a region without a row has the defaults below — SBOM and scan
 * on. Regions that existed when the supply chain was installed got a row with both off
 * (migration 2026_10_10_400001), so an upgrade never starts Jobs an administrator did not ask for.
 *
 * The private signing key (cosign's encrypted key) and its password are secret columns
 * (App\Casts\Encrypted, App\Support\Secrets\SecretColumns), hidden from serialization and never
 * returned by the API.
 *
 * @property string $knative_region_id
 * @property string|null $owner_tenant_id
 * @property bool $sbom_enabled
 * @property bool $scan_enabled
 * @property bool $scan_external_images
 * @property int $rescan_interval_hours
 * @property string $scanner_db_mode online | mirror | offline
 * @property string|null $scanner_db_source
 * @property string $scanner_db_status
 * @property Carbon|null $scanner_db_updated_at
 * @property Carbon|null $scanner_db_refreshed_at
 * @property string|null $scanner_db_message
 * @property string|null $scanner_db_job
 * @property string $gate_mode off | warn | enforce
 * @property int|null $gate_max_critical
 * @property int|null $gate_max_high
 * @property bool $gate_only_fixable
 * @property bool $gate_require_scan
 * @property bool $signing_enabled
 * @property bool $verify_on_deploy
 * @property bool $attest_enabled
 * @property string $signing_status none | generating | ready | failed
 * @property string|null $signing_public_key
 * @property string|null $signing_key_fingerprint
 * @property string|null $signing_private_key
 * @property string|null $signing_key_password
 * @property Carbon|null $signing_key_created_at
 * @property array|null $signing_retired_keys
 * @property string|null $signing_message
 * @property string|null $signing_job
 * @property string $admission_mode off | warn | enforce
 * @property string $admission_status
 * @property string|null $admission_message
 * @property bool $admission_require_sbom
 * @property array|null $admission_exempt_images
 * @property string $caas_policy any | allowlist | signed
 * @property array|null $caas_allowed_registries
 * @property array|null $caas_trusted_keys
 * @property string|null $data_location
 * @property string|null $updated_by
 */
class RegionSupplyChainSetting extends Model
{
    public const DB_MODES = ['online', 'mirror', 'offline'];

    public const GATE_MODES = ['off', 'warn', 'enforce'];

    public const ADMISSION_MODES = ['off', 'warn', 'enforce'];

    public const CAAS_POLICIES = ['any', 'allowlist', 'signed'];

    protected $table = 'region_supply_chain_settings';

    protected $primaryKey = 'knative_region_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'sbom_enabled' => true,
        'scan_enabled' => true,
        'scan_external_images' => false,
        'rescan_interval_hours' => 24,
        'scanner_db_mode' => 'online',
        'scanner_db_status' => 'none',
        'gate_mode' => 'off',
        'gate_max_critical' => 0,
        'gate_only_fixable' => false,
        'gate_require_scan' => false,
        'signing_enabled' => false,
        'verify_on_deploy' => true,
        'attest_enabled' => true,
        'signing_status' => 'none',
        'admission_mode' => 'off',
        'admission_status' => 'not_installed',
        'admission_require_sbom' => false,
        'caas_policy' => 'any',
    ];

    protected $fillable = [
        'knative_region_id', 'owner_tenant_id',
        'sbom_enabled', 'scan_enabled', 'scan_external_images', 'rescan_interval_hours',
        'scanner_db_mode', 'scanner_db_source', 'scanner_db_status', 'scanner_db_updated_at',
        'scanner_db_refreshed_at', 'scanner_db_message', 'scanner_db_job',
        'gate_mode', 'gate_max_critical', 'gate_max_high', 'gate_only_fixable', 'gate_require_scan',
        'signing_enabled', 'verify_on_deploy', 'attest_enabled', 'signing_status', 'signing_public_key',
        'signing_key_fingerprint', 'signing_private_key', 'signing_key_password', 'signing_key_created_at',
        'signing_retired_keys', 'signing_message', 'signing_job',
        'admission_mode', 'admission_status', 'admission_message', 'admission_require_sbom',
        'admission_exempt_images', 'caas_policy', 'caas_allowed_registries', 'caas_trusted_keys',
        'data_location', 'updated_by',
    ];

    protected $hidden = ['signing_private_key', 'signing_key_password'];

    protected $casts = [
        'sbom_enabled' => 'boolean',
        'scan_enabled' => 'boolean',
        'scan_external_images' => 'boolean',
        'rescan_interval_hours' => 'integer',
        'scanner_db_updated_at' => 'datetime',
        'scanner_db_refreshed_at' => 'datetime',
        'gate_max_critical' => 'integer',
        'gate_max_high' => 'integer',
        'gate_only_fixable' => 'boolean',
        'gate_require_scan' => 'boolean',
        'signing_enabled' => 'boolean',
        'verify_on_deploy' => 'boolean',
        'attest_enabled' => 'boolean',
        'signing_private_key' => Encrypted::class,
        'signing_key_password' => Encrypted::class,
        'signing_key_created_at' => 'datetime',
        'signing_retired_keys' => 'array',
        'admission_require_sbom' => 'boolean',
        'admission_exempt_images' => 'array',
        'caas_allowed_registries' => 'array',
        'caas_trusted_keys' => 'array',
    ];

    public function getConnectionName()
    {
        return SupplyChainDb::central() ?? parent::getConnectionName();
    }

    /** Settings of a region: its row, or an unsaved instance with the defaults. */
    public static function forRegion(KnativeRegion|string $region): self
    {
        $id = $region instanceof KnativeRegion ? $region->getKey() : $region;

        return static::query()->find($id) ?? static::defaultsFor($region);
    }

    /** Row of a region, created with the defaults when it has none. */
    public static function firstOrCreateFor(KnativeRegion $region): self
    {
        $settings = static::query()->find($region->getKey());
        if ($settings) {
            return $settings;
        }

        $settings = static::defaultsFor($region);
        $settings->save();

        return $settings;
    }

    private static function defaultsFor(KnativeRegion|string $region): self
    {
        $settings = new static;
        $settings->knative_region_id = $region instanceof KnativeRegion ? $region->getKey() : $region;
        $settings->rescan_interval_hours = (int) config('supply_chain.scan.rescan_interval_hours', 24);

        if ($region instanceof KnativeRegion) {
            $attributes = $region->getAttributes();
            $settings->owner_tenant_id = empty($attributes['is_synced']) ? ($attributes['owner_tenant_id'] ?? null) : null;
        }

        return $settings;
    }

    public function hasSigningKey(): bool
    {
        return $this->signing_status === 'ready' && filled($this->signing_public_key);
    }

    /**
     * Public keys an image signature may come from: the current key and the retired ones that
     * were not forgotten yet (images signed before a rotation keep deploying).
     *
     * @return list<array{public_key: string, fingerprint: string, current: bool}>
     */
    public function trustedKeys(): array
    {
        $keys = [];
        if ($this->hasSigningKey()) {
            $keys[] = ['public_key' => $this->signing_public_key, 'fingerprint' => (string) $this->signing_key_fingerprint, 'current' => true];
        }
        foreach ($this->signing_retired_keys ?? [] as $retired) {
            if (! empty($retired['public_key']) && ! empty($retired['fingerprint'])) {
                $keys[] = ['public_key' => $retired['public_key'], 'fingerprint' => $retired['fingerprint'], 'current' => false];
            }
        }

        // A rotation that failed leaves the current key in the retired list as well
        $unique = [];
        foreach ($keys as $key) {
            $unique[$key['fingerprint']] ??= $key;
        }

        return array_values($unique);
    }

    /** @return list<string> fingerprints of trustedKeys() */
    public function trustedFingerprints(): array
    {
        return array_column($this->trustedKeys(), 'fingerprint');
    }
}
