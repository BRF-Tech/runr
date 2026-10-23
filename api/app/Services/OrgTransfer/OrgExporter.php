<?php

namespace App\Services\OrgTransfer;

use App\Models\AuditEvent;
use App\Models\Binding;
use App\Models\Deployment;
use App\Models\OrgTransfer;
use App\Models\Project;
use App\Models\TriggerEvent;
use App\Models\Version;
use App\Services\GitService;
use App\Services\OrgTransfer\Destinations\PackageDestinations;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Services\OrgTransfer\Package\PackageWriter;
use App\Services\OrgTransfer\Package\PassphraseBox;
use App\Services\OrgTransfer\Sections\BindingDataSection;
use App\Services\OrgTransfer\Sections\ImagesSection;
use App\Services\OrgTransfer\Sections\SectionRegistry;
use App\Workflows\Models\WorkflowRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Writes an organization package (docs/org-export.md#export): every section into a staging
 * directory, the secrets sealed with the passphrase (only when the user included them), the
 * manifest, then the package — kept for download or written to its destination. Progress goes
 * to the transfer row and the console (OrgTransferProgress); a cancelled export stops between
 * two steps and leaves nothing behind.
 */
class OrgExporter
{
    public function __construct(
        private SectionRegistry $sections,
        private PackageWriter $writer,
        private PackageDestinations $destinations,
        private OrgTransferProgress $progress,
    ) {}

    /**
     * @param  array<string, mixed>|null  $credentials  S3 credentials of the destination (this export only)
     */
    public function run(OrgTransfer $transfer, ?string $passphrase, ?array $credentials = null): void
    {
        $this->progress->update($transfer, ['status' => OrgTransfer::STATUS_RUNNING, 'phase' => 'prepare', 'progress' => 1, 'started_at' => now(), 'message' => null]);

        $staging = OrgTransferPaths::staging($transfer);
        OrgTransferPaths::remove($transfer, 'staging');
        OrgTransferPaths::ensure($staging);

        try {
            $options = (array) $transfer->options;
            $context = new ExportContext($transfer, $staging, $options, $this->progress);

            foreach ($this->sections->sections() as $section) {
                $context->assertNotCancelled();
                $section->export($context);
                $context->sections[] = $section->key();
            }

            $encryption = null;
            if ($context->includesSecrets()) {
                $this->progress->update($transfer, ['phase' => 'secrets', 'progress' => 86]);
                $sealed = PassphraseBox::seal(json_encode($context->secrets(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string) $passphrase);
                file_put_contents($context->path(PackageFormat::SECRETS), $sealed);
                $encryption = ['secrets' => ['file' => PackageFormat::SECRETS, 'alg' => PassphraseBox::ALGORITHM]];
            }

            $organization = OrgTransferOwner::organization();
            $at = now();
            $filename = PackageFormat::filename($organization['slug'], $at, config('org_transfer.compression', 'zstd') !== 'none');
            $manifest = [
                'format' => PackageFormat::FORMAT,
                'schema_version' => PackageFormat::SCHEMA_VERSION,
                'runr_version' => (string) config('app.version', 'dev'),
                'created_at' => $at->toIso8601String(),
                'source' => $organization + ['exported_by' => $transfer->created_by_name],
                'options' => self::manifestOptions($options),
                'contents' => $context->contents,
                'sections' => $context->sections,
                'projects' => $context->projects()->pluck('id')->values()->all(),
                'encryption' => $encryption,
            ];

            $context->assertNotCancelled();
            $this->progress->update($transfer, ['phase' => 'packing', 'progress' => 87, 'message' => __('org_transfer.progress.packing')]);
            $package = OrgTransferPaths::package($transfer);
            $packed = $this->writer->pack($staging, $manifest, $package,
                fn ($done, $total) => $this->progress->step($transfer, 'packing', 87, 96, $done, $total, __('org_transfer.progress.packing')));
            OrgTransferPaths::remove($transfer, 'staging');

            $destination = (array) ($transfer->destination ?? ['type' => 'download']);
            if (($destination['type'] ?? 'download') !== 'download') {
                $context->assertNotCancelled();
                $this->progress->update($transfer, ['phase' => 'destination', 'progress' => 96, 'message' => __('org_transfer.progress.destination')]);
                $destination['key'] = $this->destinations->write($package, $filename, $destination, $credentials,
                    fn ($done, $total) => $this->progress->step($transfer, 'destination', 96, 99, $done, $total, __('org_transfer.progress.destination')));
                @unlink($package);
            }

            $this->progress->update($transfer, [
                'status' => OrgTransfer::STATUS_COMPLETED,
                'phase' => 'done',
                'progress' => 100,
                'message' => null,
                'destination' => $destination,
                'package_filename' => $filename,
                'package_size' => $packed['size'],
                'package_sha256' => $packed['sha256'],
                'schema_version' => PackageFormat::SCHEMA_VERSION,
                'source_runr_version' => $manifest['runr_version'],
                'package_source' => ['organization' => $organization['organization'], 'slug' => $organization['slug'], 'exported_at' => $manifest['created_at']],
                'report' => [
                    'entries' => $packed['entries'],
                    'bytes' => $packed['size'],
                    'contents' => $context->contents,
                    'warnings' => $context->warnings,
                    'skipped' => $context->skipped,
                ],
                'finished_at' => now(),
                'expires_at' => now()->addHours((int) config('org_transfer.retention_hours', 168)),
            ]);

            OrgTransferOwner::audit($transfer, 'org.export.completed', AuditEvent::RESULT_SUCCESS, [
                'contents' => $context->contents,
                'secrets_included' => $context->includesSecrets(),
                'destination' => $destination['type'] ?? 'download',
                'size' => $packed['size'],
                'sha256' => $packed['sha256'],
            ]);
        } catch (TransferCancelled) {
            OrgTransferPaths::remove($transfer);
            Log::info('Organization export cancelled', ['transfer' => $transfer->id]);
        } catch (\Throwable $e) {
            OrgTransferPaths::remove($transfer);
            $this->progress->update($transfer, ['status' => OrgTransfer::STATUS_FAILED, 'message' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
            OrgTransferOwner::audit($transfer, 'org.export.failed', AuditEvent::RESULT_FAILURE, ['error' => mb_substr($e->getMessage(), 0, 300), 'phase' => $transfer->phase]);

            throw $e;
        }
    }

    /**
     * What an export would hold and roughly how big it is — shown before it starts: projects,
     * versions and their code, the bindings with their measured usage (data only when picked),
     * the images (their size is known only when pulled).
     *
     * @param  array<string, mixed>  $options  normalized export options
     * @return array<string, mixed>
     */
    public function estimate(array $options): array
    {
        $query = Project::query()->where('is_region_demo', false);
        if (is_array($options['projects'] ?? null)) {
            $query->whereIn('id', $options['projects']);
        }
        $projects = $query->get();

        $git = app(GitService::class);
        $codeBytes = 0;
        $versions = 0;
        foreach ($projects as $project) {
            $count = Version::query()->where('project_id', $project->id)->count();
            $versions += $count;
            $codeBytes += self::directorySize($git->repoPath().'/'.$git->projectDir($project)) * max(1, $count);
        }

        $picked = BindingDataSection::selected($options)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $bindings = Binding::query()->with('region:id,name')
            ->whereNotIn('status', [Binding::STATUS_DELETING, Binding::STATUS_PURGING])->orderBy('name')->get()
            ->map(fn (Binding $b) => [
                'id' => (string) $b->id,
                'name' => $b->name,
                'type' => $b->type,
                'plan' => $b->plan,
                'region' => $b->region?->name,
                'usage_bytes' => (int) $b->usage_bytes,
                'data_supported' => $b->type !== 'external',
                'data' => in_array((string) $b->id, $picked, true),
            ])->values()->all();
        $dataBytes = array_sum(array_map(fn ($b) => $b['data'] ? $b['usage_bytes'] : 0, $bindings));

        $images = 0;
        $warnings = [];
        if (! empty($options['include_images'])) {
            foreach ($projects as $project) {
                if ($project->service_type !== 'caas') {
                    $images += ImagesSection::versionsFor($project)->count();
                }
            }
            $warnings[] = __('org_transfer.estimate.images_unknown');
        }

        $history = null;
        if (! empty($options['include_history'])) {
            $ids = $projects->pluck('id')->all();
            $history = [
                'deployments' => Deployment::query()->whereIn('project_id', $ids)->count(),
                'workflow_runs' => Schema::hasTable('workflow_runs') ? WorkflowRun::query()->whereIn('project_id', $ids)->count() : 0,
                'trigger_events' => Schema::hasTable('trigger_events') ? TriggerEvent::query()->whereIn('project_id', $ids)->count() : 0,
            ];
        }

        return [
            'projects' => $projects->count(),
            'versions' => $versions,
            'history' => $history,
            'code_bytes' => $codeBytes,
            'bindings' => $bindings,
            'binding_data_bytes' => $dataBytes,
            'images' => ['count' => $images, 'bytes' => null],
            'total_bytes' => $codeBytes + $dataBytes,
            'warnings' => $warnings,
        ];
    }

    /** The options as the manifest records them (never the passphrase or credentials). */
    private static function manifestOptions(array $options): array
    {
        return [
            'projects' => is_array($options['projects'] ?? null) ? 'selected' : 'all',
            'include_members' => (bool) ($options['include_members'] ?? false),
            'include_images' => (bool) ($options['include_images'] ?? false),
            'binding_data' => ($options['binding_data'] ?? []) === 'all' ? 'all' : count((array) ($options['binding_data'] ?? [])),
            'include_secrets' => (bool) ($options['include_secrets'] ?? false),
            'include_history' => (bool) ($options['include_history'] ?? false),
        ];
    }

    private static function directorySize(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && ! $file->isLink()) {
                $size += (int) $file->getSize();
            }
        }

        return $size;
    }
}
