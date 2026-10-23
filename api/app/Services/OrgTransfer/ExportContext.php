<?php

namespace App\Services\OrgTransfer;

use App\Models\OrgTransfer;
use App\Models\Project;
use App\Services\OrgTransfer\Package\PackageFormat;
use Illuminate\Support\Collection;

/**
 * What the sections of an export share: the staging directory the entries go to, the export's
 * options, the secrets collected for secrets/secrets.enc (only when the user included secrets —
 * sealed with the passphrase at the end, never written in clear), counts for the manifest, and
 * the warnings / skipped entries of the report.
 */
final class ExportContext
{
    /** @var array<string, array<string, mixed>> group => key => value, sealed into secrets/secrets.enc */
    private array $secrets = [];

    /** @var array<string, int|bool|list<string>> manifest contents */
    public array $contents = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<array{section: string, name: string, message: string}> */
    public array $skipped = [];

    /** @var list<string> sections that wrote something */
    public array $sections = [];

    /** @var Collection<int, Project>|null */
    private ?Collection $projects = null;

    /**
     * @param  array<string, mixed>  $options  normalized export options (OrgTransferService::exportOptions())
     */
    public function __construct(
        public readonly OrgTransfer $transfer,
        public readonly string $dir,
        public readonly array $options,
        private readonly OrgTransferProgress $progress,
    ) {}

    /** The projects the export covers: the chosen ones, else every project of the organization. */
    public function projects(): Collection
    {
        if ($this->projects === null) {
            $ids = $this->options['projects'] ?? null;
            $query = Project::query()->where('is_region_demo', false)->orderBy('created_at');
            if (is_array($ids)) {
                $query->whereIn('id', $ids);
            }
            $this->projects = $query->get();
        }

        return $this->projects;
    }

    public function includesSecrets(): bool
    {
        return (bool) ($this->options['include_secrets'] ?? false);
    }

    /** Collect a secret for the encrypted part of the package (ignored without include_secrets). */
    public function addSecret(string $group, string $key, mixed $value): void
    {
        if ($this->includesSecrets() && $value !== null && $value !== [] && $value !== '') {
            $this->secrets[$group][$key] = $value;
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function secrets(): array
    {
        return $this->secrets;
    }

    /** Absolute path of a package entry; its directory is created. */
    public function path(string $entry): string
    {
        if (! PackageFormat::isSafePath($entry)) {
            throw new \InvalidArgumentException("Not a package path: {$entry}");
        }
        $path = $this->dir.'/'.$entry;
        OrgTransferPaths::ensure(dirname($path));

        return $path;
    }

    public function putJson(string $entry, mixed $data): void
    {
        file_put_contents($this->path($entry), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function count(string $key, int $n = 1): void
    {
        $this->contents[$key] = (int) ($this->contents[$key] ?? 0) + $n;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function skip(string $section, string $name, string $message): void
    {
        $this->skipped[] = ['section' => $section, 'name' => $name, 'message' => $message];
    }

    public function progress(string $phase, int $from, int $to, int|float $done, int|float $total, ?string $message = null): void
    {
        $this->assertNotCancelled();
        $this->progress->step($this->transfer, $phase, $from, $to, $done, $total, $message);
    }

    /** @throws TransferCancelled the user cancelled the export */
    public function assertNotCancelled(): void
    {
        $status = OrgTransfer::query()->whereKey($this->transfer->id)->value('status');
        if ($status === OrgTransfer::STATUS_CANCELLED || $status === null) {
            throw new TransferCancelled;
        }
    }
}
