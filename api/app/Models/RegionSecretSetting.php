<?php

namespace App\Models;

use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Symfony\Component\Yaml\Yaml;

/**
 * The secret features of one region (docs/secret-stores.md): External Secrets Operator on its
 * cluster, which syncs references to the organizations' secret stores into the region, and the
 * region's own OpenBao (openbao_*: installed and operated by RunR, OpenBaoInstallService). Platform-
 * wide (central schema, keyed by region id — platform and tenant-owned regions alike); a region
 * without a row has nothing installed.
 *
 * @property string $knative_region_id
 * @property string|null $owner_tenant_id
 * @property string $eso_status not_installed | installing | ready | uninstalling | error
 * @property string|null $eso_message
 * @property string|null $eso_chart_version
 * @property Carbon|null $eso_installed_at
 * @property string $openbao_status not_installed | installing | ready | uninstalling | error
 * @property string|null $openbao_message
 * @property string|null $openbao_version
 * @property Carbon|null $openbao_installed_at
 * @property string|null $openbao_ca PEM of the CA of OpenBao's TLS certificate (public)
 * @property bool $openbao_default new secret values go to OpenBao while it is ready
 */
class RegionSecretSetting extends Model
{
    use UsesPlatformConnection;

    public const ESO_NOT_INSTALLED = 'not_installed';

    public const ESO_INSTALLING = 'installing';

    public const ESO_READY = 'ready';

    public const ESO_UNINSTALLING = 'uninstalling';

    public const ESO_ERROR = 'error';

    protected $table = 'region_secret_settings';

    protected $primaryKey = 'knative_region_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'eso_status' => self::ESO_NOT_INSTALLED,
        'openbao_status' => self::ESO_NOT_INSTALLED,
        'openbao_default' => true,
    ];

    protected $fillable = [
        'knative_region_id', 'owner_tenant_id',
        'eso_status', 'eso_message', 'eso_chart_version', 'eso_installed_at',
        'openbao_status', 'openbao_message', 'openbao_version', 'openbao_installed_at', 'openbao_ca', 'openbao_default',
    ];

    protected $casts = [
        'eso_installed_at' => 'datetime',
        'openbao_installed_at' => 'datetime',
        'openbao_default' => 'boolean',
    ];

    /** Settings of a region: its row, or an unsaved instance with nothing installed. */
    public static function forRegion(KnativeRegion|string $region): self
    {
        $id = $region instanceof KnativeRegion ? $region->getKey() : $region;

        try {
            $settings = static::query()->find($id);
        } catch (QueryException) {
            // a connection without the table (a schema that is not the platform's, not migrated
            // yet): nothing is installed there
            $settings = null;
        }
        if ($settings) {
            return $settings;
        }

        $settings = new self;
        $settings->knative_region_id = (string) $id;
        if ($region instanceof KnativeRegion) {
            $attributes = $region->getAttributes();
            $settings->owner_tenant_id = empty($attributes['is_synced']) ? ($attributes['owner_tenant_id'] ?? null) : null;
        }

        return $settings;
    }

    public function esoReady(): bool
    {
        return $this->eso_status === self::ESO_READY;
    }

    public function esoBusy(): bool
    {
        return in_array($this->eso_status, [self::ESO_INSTALLING, self::ESO_UNINSTALLING], true);
    }

    /**
     * Another region (current database context) on the same cluster — the same API server — whose
     * settings pass $test: its name, or null. External Secrets Operator and OpenBao are installed
     * once per cluster, so removing them for one region must not take them from the other.
     *
     * @param  callable(self): bool  $test
     */
    public static function siblingWith(KnativeRegion $region, callable $test): ?string
    {
        $server = self::serverOf((string) $region->kubeconfig);
        if ($server === null) {
            return null;
        }

        foreach (KnativeRegion::query()->whereKeyNot($region->getKey())->whereNotNull('kubeconfig')->get() as $other) {
            if (self::serverOf((string) $other->kubeconfig) === $server && $test(self::forRegion($other))) {
                return (string) $other->name;
            }
        }

        return null;
    }

    private static function serverOf(string $kubeconfig): ?string
    {
        try {
            $server = Yaml::parse($kubeconfig)['clusters'][0]['cluster']['server'] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($server) ? rtrim($server, '/') : null;
    }

    public function openbaoReady(): bool
    {
        return $this->openbao_status === self::ESO_READY;
    }

    public function openbaoBusy(): bool
    {
        return in_array($this->openbao_status, [self::ESO_INSTALLING, self::ESO_UNINSTALLING], true);
    }

    /** New secret values of the region's projects go to its OpenBao. */
    public function valuesInOpenBao(): bool
    {
        return $this->openbaoReady() && $this->openbao_default;
    }
}
