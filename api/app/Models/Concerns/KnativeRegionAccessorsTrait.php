<?php

namespace App\Models\Concerns;

use App\Services\Manifests\ZotManifests;
use Symfony\Component\Yaml\Yaml;
use Throwable;

trait KnativeRegionAccessorsTrait
{
    /**
     * Optional resolver for kubeconfig (registered by modules).
     * Allows synced regions to transparently fetch kubeconfig from an alternate source.
     */
    protected static ?\Closure $kubeconfigResolver = null;

    public static function setKubeconfigResolver(?\Closure $resolver): void
    {
        self::$kubeconfigResolver = $resolver;
    }

    /**
     * Kubeconfig accessor with pluggable resolver.
     * When a module registers a resolver, it may fetch from an alternate connection.
     * The stored value is encrypted (cast App\Casts\Encrypted); an accessor receives the raw
     * column value, so it is decrypted here.
     */
    public function getKubeconfigAttribute($value)
    {
        if ($value !== null) {
            return $this->castAttribute('kubeconfig', $value);
        }

        if (self::$kubeconfigResolver) {
            return (self::$kubeconfigResolver)($this);
        }

        return null;
    }

    /**
     * Parse server URL from kubeconfig YAML.
     */
    public function getServerUrlAttribute(): ?string
    {
        $raw = $this->kubeconfig;
        if (! $raw || $raw === 'demo') {
            return null;
        }

        try {
            $parsed = Yaml::parse($raw);

            return $parsed['clusters'][0]['cluster']['server'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Merge user settings over defaults so every key is always present.
     */
    public function getEffectiveSettingsAttribute(): array
    {
        $settings = $this->knative_settings;
        if (is_string($settings)) {
            $settings = json_decode($settings, true) ?? [];
        }

        return array_merge(self::DEFAULT_SETTINGS, $settings ?? []);
    }

    /**
     * RunR installed (and patches/reconciles) this region's Prometheus. Null — a row read from a
     * tenant schema created before TenantMigrations/0023 — counts as managed, like the column default.
     */
    public function isPrometheusManaged(): bool
    {
        return (bool) ($this->prometheus_managed ?? true);
    }

    /**
     * RunR installed this region's Loki (same null handling as isPrometheusManaged()).
     */
    public function isLokiManaged(): bool
    {
        return (bool) ($this->loki_managed ?? true);
    }

    public function getHasRedisAttribute(): bool
    {
        return $this->redis_mode !== null && $this->knative_redis_status === 'ready';
    }

    /**
     * Computed knative_status from component statuses.
     * All ready → ready, any installing → installing, any error → error, etc.
     */
    public function getKnativeStatusAttribute($value): string
    {
        $statuses = [
            $this->attributes['knative_operator_status'] ?? 'not_installed',
            $this->attributes['knative_serving_status'] ?? 'not_installed',
        ];

        if ($value && ! array_filter($statuses, fn ($s) => $s !== 'not_installed')) {
            return $value;
        }

        if (in_array('installing', $statuses)) {
            return 'installing';
        }
        if (in_array('uninstalling', $statuses)) {
            return 'uninstalling';
        }
        if (in_array('error', $statuses)) {
            return 'error';
        }

        $readyCount = count(array_filter($statuses, fn ($s) => $s === 'ready'));
        if ($readyCount === 2) {
            return 'ready';
        }
        if ($readyCount > 0) {
            return 'degraded';
        }

        return $value ?? 'unknown';
    }

    public function getHasRegistryAttribute(): bool
    {
        return $this->registry_mode !== null && $this->registry_status === 'ready';
    }

    public function registryImageRef(string $image): string
    {
        if (! $this->has_registry || ! $this->registry_address) {
            return $image;
        }

        $firstSlash = strpos($image, '/');
        if ($firstSlash !== false) {
            $host = substr($image, 0, $firstSlash);
            if (str_contains($host, '.') || str_contains($host, ':')) {
                return $image;
            }
        }

        return rtrim($this->registry_address, '/').'/'.$image;
    }

    /**
     * Image reference for a FROM line of an in-region Kaniko build (builder and runtime base).
     * With the internal (Zot) registry and its ghcr.io upstream ($ghcrSynced, see
     * ZotRegistryService::syncsGhcr()) a ghcr.io image comes through the pull-through cache —
     * {registry}/ghcr.io/brf-tech/runr/exec-python:3.14 is fetched from ghcr.io once per tag, not on
     * every build. Anything else maps as registryImageRef().
     */
    public function buildBaseImageRef(string $image, bool $ghcrSynced): string
    {
        if ($ghcrSynced
            && $this->registry_mode === 'internal'
            && $this->has_registry
            && $this->registry_address
            && str_starts_with($image, ZotManifests::GHCR_NAMESPACE.'/')) {
            return rtrim($this->registry_address, '/').'/'.$image;
        }

        return $this->registryImageRef($image);
    }

    public function getHasServingAttribute(): bool
    {
        return ($this->attributes['knative_serving_status'] ?? 'not_installed') === 'ready';
    }

    public function getHasSandboxKataAttribute(): bool
    {
        return ($this->attributes['sandbox_kata_status'] ?? 'not_installed') === 'ready';
    }

    public function getHasSandboxGvisorAttribute(): bool
    {
        return ($this->attributes['sandbox_gvisor_status'] ?? 'not_installed') === 'ready';
    }

    /**
     * Resolve the Kubernetes RuntimeClass name for a given project type.
     */
    public function resolveRuntimeClass(string $serviceType): ?string
    {
        $mapping = match ($serviceType) {
            'xaas' => $this->sandbox_xaas_runtime,
            'faas' => $this->sandbox_faas_runtime,
            'caas' => $this->sandbox_caas_runtime,
            default => 'runc',
        };

        return self::runtimeToClassName($mapping);
    }

    public function resolveBuildRuntimeClass(): ?string
    {
        return self::runtimeToClassName($this->sandbox_build_runtime);
    }

    public static function runtimeToClassName(?string $runtime): ?string
    {
        if ($runtime && str_starts_with($runtime, 'kata-')) {
            return $runtime; // kata-clh, kata-qemu, kata-dragonball
        }

        return match ($runtime) {
            'kata' => 'kata-clh', // legacy fallback
            'gvisor' => 'gvisor',
            default => null,
        };
    }

    public function setRegionStatusAttribute(string $value): void
    {
        $this->attributes['region_status'] = $value;
        $this->attributes['is_active'] = ($value === 'active');
    }

    /**
     * is_active (the legacy flag, still accepted by POST/PUT /knative-regions) keeps
     * region_status in step: a region created or switched inactive is "inactive" (an inactive
     * flag next to an "active" status refused every deploy while the console showed the region
     * active and no status block was applied); switched active, it is "active".
     */
    public function setIsActiveAttribute(mixed $value): void
    {
        $active = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        $this->attributes['is_active'] = $active;

        $status = $this->attributes['region_status'] ?? 'active';
        if (! $active && $status === 'active') {
            $this->attributes['region_status'] = 'inactive';
        } elseif ($active && $status !== 'active') {
            $this->attributes['region_status'] = 'active';
        }
    }

    public function getIsMaintenanceAttribute(): bool
    {
        return ($this->attributes['region_status'] ?? 'active') === 'maintenance';
    }

    public function getIsInactiveAttribute(): bool
    {
        return ($this->attributes['region_status'] ?? 'active') === 'inactive';
    }

    public function getIsMigratingAttribute(): bool
    {
        return in_array($this->attributes['migration_status'] ?? null, ['pending', 'in_progress', 'dns_check']);
    }

    public function getIsDomainMigratingAttribute(): bool
    {
        return in_array($this->attributes['domain_migration_status'] ?? null, ['pending', 'dns_check']);
    }
}
