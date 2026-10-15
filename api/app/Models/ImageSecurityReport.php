<?php

namespace App\Models;

use App\Services\SupplyChain\SupplyChainDb;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Supply-chain report of one image: the latest run of the supply-chain Job (SBOM, scan,
 * referrers, signature, attestations) for a function version (subject "version", stored with
 * the project — in the tenant's schema for tenant projects), an AI model service's image
 * (subject "model_service", external image, stored like the service) or a runtime image built in
 * a region (subject "runtime_build", always in the central schema — onCentral()) or an uploaded
 * image (subject "uploaded_image", central schema like the uploads; docs/images.md).
 *
 * image_kind: built (RunR built it into the region registry: full pipeline), runtime (runtime
 * image built from source by RunR), external (CaaS image: SBOM + scan only, nothing is written
 * to its registry), uploaded (an image uploaded into the region registry — or a CaaS version
 * running one: the full pipeline, RunR owns the registry), wasm (a Spin app RunR built: a
 * WebAssembly OCI artifact without a container filesystem, so syft and Trivy have nothing to
 * read — signed only; SBOM, referrers, scan and attestations are skipped with the reason).
 *
 * Step statuses: pending | running | ok | failed | skipped.
 *
 * @property string $id
 * @property string $subject_type version | runtime_build
 * @property string $subject_id
 * @property string|null $project_id
 * @property string|null $version_id
 * @property string $knative_region_id
 * @property string $image_kind built | runtime | external | uploaded | wasm
 * @property string $image_ref
 * @property string|null $image_digest
 * @property string $status pending | running | completed | failed
 * @property string|null $job_name
 * @property array|null $steps
 * @property array|null $step_results
 * @property string $sbom_status
 * @property string $scan_status
 * @property string $signature_status
 * @property string $attestation_status
 * @property string $referrer_status
 * @property array|null $sbom_summary
 * @property int $critical_count
 * @property int $high_count
 * @property int $medium_count
 * @property int $low_count
 * @property int $unknown_count
 * @property int $fixable_critical_count
 * @property int $fixable_high_count
 * @property int $findings_total
 * @property array|null $findings
 * @property array|null $scanner
 * @property Carbon|null $scanned_at
 * @property Carbon|null $signed_at
 * @property string|null $signature_key_fingerprint
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class ImageSecurityReport extends Model
{
    use HasUlids;

    public const SUBJECT_VERSION = 'version';

    public const SUBJECT_RUNTIME_BUILD = 'runtime_build';

    public const SUBJECT_MODEL_SERVICE = 'model_service';   // AI model service image (external)

    public const SUBJECT_UPLOADED_IMAGE = 'uploaded_image'; // an uploaded image (docs/images.md), central schema

    public const KIND_BUILT = 'built';

    public const KIND_RUNTIME = 'runtime';

    public const KIND_EXTERNAL = 'external';

    public const KIND_UPLOADED = 'uploaded'; // uploaded into the region registry: the full pipeline, like a build

    public const KIND_WASM = 'wasm'; // a Spin app (WebAssembly OCI artifact) RunR built: signed, no SBOM / scan

    public const STEP_PENDING = 'pending';

    public const STEP_RUNNING = 'running';

    public const STEP_OK = 'ok';

    public const STEP_FAILED = 'failed';

    public const STEP_SKIPPED = 'skipped';

    /** Pipeline steps in the order the tools image runs them */
    public const STEPS = ['sbom', 'attach', 'scan', 'sign', 'attest'];

    protected $attributes = [
        'status' => 'pending',
        'sbom_status' => 'pending',
        'scan_status' => 'pending',
        'signature_status' => 'pending',
        'attestation_status' => 'pending',
        'referrer_status' => 'pending',
    ];

    protected $fillable = [
        'subject_type', 'subject_id', 'project_id', 'version_id', 'knative_region_id',
        'image_kind', 'image_ref', 'image_digest', 'status', 'job_name', 'steps', 'step_results',
        'sbom_status', 'scan_status', 'signature_status', 'attestation_status', 'referrer_status',
        'sbom_summary', 'critical_count', 'high_count', 'medium_count', 'low_count', 'unknown_count',
        'fixable_critical_count', 'fixable_high_count', 'findings_total', 'findings', 'scanner',
        'scanned_at', 'signed_at', 'signature_key_fingerprint', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'steps' => 'array',
        'step_results' => 'array',
        'sbom_summary' => 'array',
        'findings' => 'array',
        'scanner' => 'array',
        'critical_count' => 'integer',
        'high_count' => 'integer',
        'medium_count' => 'integer',
        'low_count' => 'integer',
        'unknown_count' => 'integer',
        'fixable_critical_count' => 'integer',
        'fixable_high_count' => 'integer',
        'findings_total' => 'integer',
        'scanned_at' => 'datetime',
        'signed_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** Query of the central schema (runtime image reports, also from tenant context). */
    public static function onCentral(): Builder
    {
        $connection = SupplyChainDb::central();

        return $connection ? static::on($connection) : static::query();
    }

    /** Report of a version (latest run), null when it has none. */
    public static function forVersion(Version|string $version): ?self
    {
        return static::query()
            ->where('subject_type', self::SUBJECT_VERSION)
            ->where('subject_id', $version instanceof Version ? $version->getKey() : $version)
            ->first();
    }

    public static function forRuntimeBuild(RuntimeBuild|string $build): ?self
    {
        return static::onCentral()
            ->where('subject_type', self::SUBJECT_RUNTIME_BUILD)
            ->where('subject_id', $build instanceof RuntimeBuild ? $build->getKey() : $build)
            ->first();
    }

    /** Report of an uploaded image (central schema), null when it has none. */
    public static function forUploadedImage(UploadedImage|string $image): ?self
    {
        return static::onCentral()
            ->where('subject_type', self::SUBJECT_UPLOADED_IMAGE)
            ->where('subject_id', $image instanceof UploadedImage ? $image->getKey() : $image)
            ->first();
    }

    /** Report of an AI model service's image (latest run), null when it has none. */
    public static function forModelService(AiModelService|string $service): ?self
    {
        return static::query()
            ->where('subject_type', self::SUBJECT_MODEL_SERVICE)
            ->where('subject_id', $service instanceof AiModelService ? $service->getKey() : $service)
            ->first();
    }

    public function sbomDocuments(): HasMany
    {
        return $this->hasMany(ImageSbomDocument::class, 'report_id');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['pending', 'running'], true);
    }

    public function hasScan(): bool
    {
        return $this->scan_status === self::STEP_OK;
    }

    public function isSigned(): bool
    {
        return $this->signature_status === self::STEP_OK;
    }

    /** Severity counts, optionally only the findings with a fixed version. */
    public function counts(bool $onlyFixable = false): array
    {
        return [
            'critical' => $onlyFixable ? $this->fixable_critical_count : $this->critical_count,
            'high' => $onlyFixable ? $this->fixable_high_count : $this->high_count,
            'medium' => $this->medium_count,
            'low' => $this->low_count,
            'unknown' => $this->unknown_count,
        ];
    }

    /** Compact view (lists, badges); findings and SBOM documents are left out. */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'subject_type' => $this->subject_type,
            'version_id' => $this->version_id,
            'image_kind' => $this->image_kind,
            'image_ref' => $this->image_ref,
            'image_digest' => $this->image_digest,
            'status' => $this->status,
            'sbom_status' => $this->sbom_status,
            'scan_status' => $this->scan_status,
            'signature_status' => $this->signature_status,
            'attestation_status' => $this->attestation_status,
            'referrer_status' => $this->referrer_status,
            'counts' => $this->counts(),
            'fixable' => ['critical' => $this->fixable_critical_count, 'high' => $this->fixable_high_count],
            'findings_total' => $this->findings_total,
            'packages' => $this->sbom_summary['packages'] ?? null,
            'scanned_at' => $this->scanned_at?->toISOString(),
            'signed_at' => $this->signed_at?->toISOString(),
            'signature_key_fingerprint' => $this->signature_key_fingerprint,
            'finished_at' => $this->finished_at?->toISOString(),
            'error' => $this->error,
        ];
    }

    /** Full view: summary plus findings, SBOM summary, scanner, steps and the available SBOM formats. */
    public function detail(): array
    {
        return $this->summary() + [
            'findings' => $this->findings ?? [],
            'sbom_summary' => $this->sbom_summary,
            'scanner' => $this->scanner,
            'steps' => $this->steps ?? [],
            'step_results' => $this->step_results ?? [],
            // in the catalog's order (SPDX, CycloneDX), not the rows' (ULIDs of one millisecond)
            'sbom_formats' => array_values(array_intersect(array_keys(ImageSbomDocument::FORMATS), $this->sbomDocuments()->pluck('format')->all())),
            'started_at' => $this->started_at?->toISOString(),
        ];
    }
}
