<?php

namespace App\Services\OrgTransfer;

use App\Models\AuditEvent;
use App\Models\KnativeRegion;
use App\Models\OrgTransfer;
use App\Models\OrgTransferItem;
use App\Models\User;
use App\Services\OrgTransfer\Package\PackageException;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Services\OrgTransfer\Package\PackageReader;
use App\Services\OrgTransfer\Sections\SectionRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Creates an organization from a package (docs/org-export.md#import):
 *
 *  verify()  the uploaded package: unpacked and every entry checked against the manifest
 *            (size, SHA-256, nothing missing or extra), a newer schema version refused; then the
 *            default plan
 *  plan()    the dry-run report for the user's choices (region mapping, renames, passphrase)
 *  apply()   every section in order; each entry once (org_transfer_items), so an apply that
 *            failed half-way is applied again from where it stopped. The report lists what was
 *            created, what failed and the manual steps.
 */
class OrgImporter
{
    public function __construct(
        private SectionRegistry $sections,
        private PackageReader $reader,
        private OrgTransferProgress $progress,
    ) {}

    /** @throws PackageException */
    public function verify(OrgTransfer $transfer, ImportChoices $choices): void
    {
        $this->progress->update($transfer, ['status' => OrgTransfer::STATUS_VERIFYING, 'phase' => 'verify', 'progress' => 0, 'message' => __('org_transfer.progress.verifying'), 'started_at' => now()]);

        try {
            $manifest = $this->extract($transfer);
            $this->recordManifest($transfer, $manifest);

            $plan = $this->plan($transfer, $choices);
            $this->progress->update($transfer, [
                'status' => OrgTransfer::STATUS_PLANNED,
                'phase' => 'plan',
                'progress' => 100,
                'message' => null,
                'plan' => $plan,
                'options' => $choices->toStored() + ['filename' => $transfer->options['filename'] ?? null],
            ]);
        } catch (PackageException $e) {
            OrgTransferPaths::remove($transfer, 'extracted');
            $this->progress->update($transfer, ['status' => OrgTransfer::STATUS_FAILED, 'phase' => 'verify', 'message' => $e->getMessage(), 'finished_at' => now()]);
            OrgTransferOwner::audit($transfer, 'org.import.rejected', AuditEvent::RESULT_FAILURE, ['code' => $e->errorCode, 'error' => mb_substr($e->getMessage(), 0, 300)]);

            throw $e;
        }
    }

    /**
     * The dry-run report of the package for $choices.
     *
     * @return array<string, mixed>
     *
     * @throws PackageException
     */
    public function plan(OrgTransfer $transfer, ImportChoices $choices, ?User $user = null): array
    {
        $manifest = $this->manifest($transfer);
        $context = new ImportContext($transfer, OrgTransferPaths::extracted($transfer), $manifest, $choices, $user ?? self::user($transfer));
        $plan = new ImportPlan;

        $plan->source = [
            'organization' => $manifest['source']['organization'] ?? null,
            'slug' => $manifest['source']['slug'] ?? null,
            'edition' => $manifest['source']['edition'] ?? null,
            'runr_version' => $manifest['runr_version'] ?? null,
            'schema_version' => $manifest['schema_version'] ?? null,
            'exported_at' => $manifest['created_at'] ?? null,
        ];
        $plan->contents = array_merge((array) ($manifest['contents'] ?? []), [
            'secrets' => $context->secretsState(),
            'extensions' => array_values(array_diff((array) ($manifest['sections'] ?? []), $this->sections->keys())),
        ]);

        if ($plan->contents['secrets'] === 'locked' && $choices->passphrase !== null) {
            $plan->generalIssue(ImportPlan::WARNING, 'wrong_passphrase', __('org_transfer.package.wrong_passphrase'));
        }
        foreach ($plan->contents['extensions'] as $unknown) {
            $plan->generalIssue(ImportPlan::WARNING, 'extension_unknown', __('org_transfer.plan.extension_unknown', ['section' => $unknown]));
        }

        $targets = $context->targetRegions();
        $options = $targets->map(fn (KnativeRegion $r) => ['id' => (string) $r->id, 'name' => $r->name])->values()->all();
        foreach ($context->sourceRegions() as $source) {
            $target = $context->regionFor((string) ($source['id'] ?? ''));
            $plan->regions[] = [
                'source_id' => (string) ($source['id'] ?? ''),
                'source_name' => $source['name'] ?? null,
                'target_id' => $target?->id,
                'target_name' => $target?->name,
                'options' => $options,
            ];
        }

        foreach ($this->sections->sections() as $section) {
            $section->plan($context, $plan);
        }

        return $plan->toArray();
    }

    /**
     * Create everything; per entry once. Ends completed, or failed with the entries that failed
     * (applying again retries them).
     */
    public function apply(OrgTransfer $transfer, ImportChoices $choices, ?User $user): void
    {
        $this->progress->update($transfer, ['status' => OrgTransfer::STATUS_APPLYING, 'phase' => 'apply', 'progress' => 1, 'message' => null, 'started_at' => $transfer->started_at ?? now()]);

        try {
            $manifest = $this->manifest($transfer);
            $context = new ImportContext($transfer, OrgTransferPaths::extracted($transfer), $manifest, $choices, $user, $this->progress);

            $sections = $this->sections->sections();
            foreach ($sections as $i => $section) {
                $context->assertNotCancelled();
                $this->progress->update($transfer, ['phase' => $section->key()]);
                $section->import($context);
            }

            $report = $this->report($transfer, $context);
            $failed = (int) $report['counts']['failed'];
            $this->progress->update($transfer, [
                'status' => $failed > 0 ? OrgTransfer::STATUS_FAILED : OrgTransfer::STATUS_COMPLETED,
                'phase' => 'done',
                'progress' => 100,
                'message' => $failed > 0 ? __('org_transfer.import.entries_failed', ['count' => $failed]) : null,
                'report' => $report,
                'finished_at' => now(),
                'expires_at' => now()->addHours((int) config('org_transfer.retention_hours', 168)),
            ]);

            OrgTransferOwner::audit($transfer, $failed > 0 ? 'org.import.failed' : 'org.import.completed', $failed > 0 ? AuditEvent::RESULT_FAILURE : AuditEvent::RESULT_SUCCESS, [
                'source_organization' => $manifest['source']['organization'] ?? null,
                'source_runr_version' => $manifest['runr_version'] ?? null,
                'schema_version' => $manifest['schema_version'] ?? null,
                'counts' => $report['counts'],
                'secrets' => $context->secretsState(),
            ], $user);
        } catch (TransferCancelled) {
            Log::info('Organization import cancelled', ['transfer' => $transfer->id]);
        } catch (\Throwable $e) {
            $this->progress->update($transfer, ['status' => OrgTransfer::STATUS_FAILED, 'message' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
            OrgTransferOwner::audit($transfer, 'org.import.failed', AuditEvent::RESULT_FAILURE, ['error' => mb_substr($e->getMessage(), 0, 300), 'phase' => $transfer->phase], $user);

            throw $e;
        }
    }

    /**
     * The report: every entry with its result, counts, manual steps, warnings.
     *
     * @return array<string, mixed>
     */
    private function report(OrgTransfer $transfer, ImportContext $context): array
    {
        $items = OrgTransferItem::query()->where('org_transfer_id', $transfer->id)->orderBy('id')->get();
        $counts = ['created' => 0, 'exists' => 0, 'skipped' => 0, 'failed' => 0, 'manual' => 0];
        foreach ($items as $item) {
            $counts[$item->status] = ($counts[$item->status] ?? 0) + 1;
        }

        return [
            'started_at' => $transfer->started_at?->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'counts' => $counts,
            'items' => $items->map(fn (OrgTransferItem $i) => $i->toApi())->all(),
            'manual_steps' => $context->manualSteps,
            'warnings' => $context->warnings,
        ];
    }

    /**
     * The manifest of the unpacked package; the package is unpacked again when its copy is gone
     * (an import planned before a restart of a pod without the shared volume).
     *
     * @return array<string, mixed>
     *
     * @throws PackageException
     */
    private function manifest(OrgTransfer $transfer): array
    {
        $dir = OrgTransferPaths::extracted($transfer);
        $path = $dir.'/'.PackageFormat::MANIFEST;
        if (! is_file($path)) {
            return $this->extract($transfer);
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        PackageFormat::assertReadable($manifest);

        return $manifest;
    }

    /** @return array<string, mixed> */
    private function extract(OrgTransfer $transfer): array
    {
        $package = OrgTransferPaths::package($transfer);
        if (! is_file($package)) {
            throw new PackageException(__('org_transfer.package.missing'), 'package_missing');
        }

        $dir = OrgTransferPaths::extracted($transfer);
        OrgTransferPaths::remove($transfer, 'extracted');
        $manifest = $this->reader->extract($package, $dir,
            fn ($done, $total) => $this->progress->step($transfer, 'verify', 0, 90, $done, $total, __('org_transfer.progress.verifying')));
        file_put_contents($dir.'/'.PackageFormat::MANIFEST, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $manifest;
    }

    private function recordManifest(OrgTransfer $transfer, array $manifest): void
    {
        $transfer->forceFill([
            'schema_version' => (int) $manifest['schema_version'],
            'source_runr_version' => mb_substr((string) ($manifest['runr_version'] ?? ''), 0, 64),
            'package_filename' => $transfer->package_filename ?? ($transfer->options['filename'] ?? null),
            'package_size' => $transfer->upload_size,
            'package_sha256' => (string) hash_file('sha256', OrgTransferPaths::package($transfer)),
            'package_source' => [
                'organization' => $manifest['source']['organization'] ?? null,
                'slug' => $manifest['source']['slug'] ?? null,
                'exported_at' => $manifest['created_at'] ?? null,
            ],
        ])->save();
    }

    private static function user(OrgTransfer $transfer): ?User
    {
        return $transfer->created_by !== null ? User::query()->find($transfer->created_by) : null;
    }
}
