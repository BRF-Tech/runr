<?php

namespace App\Models\Observability;

use App\Casts\Encrypted;
use App\Models\KnativeRegion;
use App\Models\Observability\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Tracing backend of a region (central, keyed by region id — platform and tenant-owned regions):
 *
 *  - managed:  RunR installs the OpenTelemetry Collector + Grafana Tempo in 'observability'
 *  - reuse:    RunR installs the collector only; it exports to an existing in-cluster Tempo
 *              (tempo_otlp_endpoint) and RunR queries that Tempo (tempo_url, tempo_org_id)
 *  - external: RunR installs the collector only; it exports to an OTLP endpoint outside the
 *              cluster (export_endpoint, export_headers) — traces are read in that backend
 *  - off:      nothing installed, projects of the region cannot turn tracing on
 *
 * status: null (not installed) | installing | ready | error.
 *
 * @property string $region_id
 * @property string|null $tenant_id
 * @property string $mode
 * @property string|null $status
 * @property string|null $status_message
 * @property string|null $tempo_url
 * @property string|null $tempo_otlp_endpoint
 * @property string|null $tempo_org_id
 * @property string|null $export_endpoint
 * @property array|null $export_headers
 * @property float $knative_sample_rate
 * @property int $retention_hours
 * @property Carbon|null $installed_at
 */
class RegionTracingConfig extends Model
{
    use HasUlids, UsesCentralConnection;

    public const MODES = ['managed', 'reuse', 'external', 'off'];

    public const STATUS_INSTALLING = 'installing';

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    protected $table = 'region_tracing_configs';

    protected $fillable = [
        'region_id', 'tenant_id', 'mode', 'status', 'status_message',
        'tempo_url', 'tempo_otlp_endpoint', 'tempo_org_id',
        'export_endpoint', 'export_headers', 'knative_sample_rate', 'retention_hours', 'installed_at',
    ];

    /** export_headers carry credentials of the external backend: never returned by the API. */
    protected $hidden = ['export_headers'];

    protected $casts = [
        'export_headers' => Encrypted::class.':array',
        'knative_sample_rate' => 'float',
        'retention_hours' => 'integer',
        'installed_at' => 'datetime',
    ];

    /** The region's configuration; an unsaved default one when the region has none yet. */
    public static function forRegion(KnativeRegion $region): self
    {
        try {
            $config = self::query()->where('region_id', $region->getKey())->first();
        } catch (QueryException) {
            $config = null;
        }

        return $config ?? new self([
            'region_id' => $region->getKey(),
            'tenant_id' => $region->owner_tenant_id ?? null,
            'mode' => self::defaultMode(),
            'knative_sample_rate' => (float) config('observability.tracing.knative_sample_rate', 0.1),
            'retention_hours' => (int) config('observability.tracing.tempo_retention_hours', 72),
        ]);
    }

    public static function defaultMode(): string
    {
        $mode = (string) config('observability.tracing.default_mode', 'managed');

        return in_array($mode, self::MODES, true) ? $mode : 'managed';
    }

    /** RunR runs a collector in this mode (everything but off). */
    public function hasCollector(): bool
    {
        return $this->mode !== 'off';
    }

    /** Traces can be queried through RunR (a Tempo it installed or reuses). */
    public function isQueryable(): bool
    {
        return in_array($this->mode, ['managed', 'reuse'], true) && $this->status === self::STATUS_READY;
    }

    /** Projects of the region may switch tracing on. */
    public function acceptsSpans(): bool
    {
        return $this->hasCollector() && $this->status === self::STATUS_READY;
    }

    /** API shape (no export headers, only their names). */
    public function toApi(): array
    {
        return [
            'region_id' => $this->region_id,
            'mode' => $this->mode,
            'status' => $this->status,
            'status_message' => $this->status_message,
            'tempo_url' => $this->tempo_url,
            'tempo_otlp_endpoint' => $this->tempo_otlp_endpoint,
            'tempo_org_id' => $this->tempo_org_id,
            'export_endpoint' => $this->export_endpoint,
            'export_header_names' => array_keys($this->export_headers ?? []),
            'knative_sample_rate' => $this->knative_sample_rate,
            'retention_hours' => $this->retention_hours,
            'installed_at' => $this->installed_at?->toIso8601String(),
            'queryable' => $this->isQueryable(),
            'accepts_spans' => $this->acceptsSpans(),
        ];
    }
}
