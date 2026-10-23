<?php

namespace App\Services\OrgTransfer;

use App\Models\KnativeRegion;
use App\Models\OrgTransfer;
use App\Models\OrgTransferItem;
use App\Models\Project;
use App\Models\User;
use App\Services\OrgTransfer\Package\PackageException;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Services\OrgTransfer\Package\PassphraseBox;
use App\Services\Residency\RegionPolicy;
use Illuminate\Support\Collection;

/**
 * What the sections of an import share: the unpacked package, its manifest, the user's choices,
 * the region mapping, the package's secrets (when a passphrase opens them), and the record of
 * what was created per entry (org_transfer_items) — an import applied again skips the entries
 * done before, so every entry is created once and a failure can be resumed.
 */
final class ImportContext
{
    /** Largest JSON entry read into memory. */
    private const JSON_MAX_BYTES = 64 * 1024 * 1024;

    /** @var array<string, mixed>|false|null decrypted secrets; false: locked */
    private array|false|null $secrets = null;

    /** @var array<string, ?KnativeRegion>|null source region id => target region */
    private ?array $regionMapping = null;

    /** @var Collection<int, KnativeRegion>|null */
    private ?Collection $targetRegions = null;

    /** @var array<string, OrgTransferItem>|null */
    private ?array $items = null;

    /** @var list<array{code: string, message: string, items: list<string>}> */
    public array $manualSteps = [];

    /** @var list<string> */
    public array $warnings = [];

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(
        public readonly OrgTransfer $transfer,
        public readonly string $dir,
        public readonly array $manifest,
        public readonly ImportChoices $choices,
        public readonly ?User $user,
        private readonly ?OrgTransferProgress $progress = null,
    ) {}

    // ── Package entries ──────────────────────────────────────────────────

    public function exists(string $entry): bool
    {
        return PackageFormat::isSafePath($entry) && is_file($this->dir.'/'.$entry);
    }

    public function file(string $entry): ?string
    {
        return $this->exists($entry) ? $this->dir.'/'.$entry : null;
    }

    public function json(string $entry, mixed $default = null): mixed
    {
        $path = $this->file($entry);
        if ($path === null || filesize($path) > self::JSON_MAX_BYTES) {
            return $default;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return $data ?? $default;
    }

    /** @return list<array<string, mixed>> project.json of every project in the package */
    public function projects(): array
    {
        $out = [];
        foreach ((array) ($this->manifest['projects'] ?? []) as $id) {
            $project = $this->json(PackageFormat::projectFile((string) $id));
            if (is_array($project)) {
                $out[] = $project;
            }
        }

        return $out;
    }

    public function sourceVersion(): int
    {
        return (int) ($this->manifest['schema_version'] ?? PackageFormat::SCHEMA_VERSION);
    }

    // ── Secrets ──────────────────────────────────────────────────────────

    /** absent (none in the package) | locked (no or a wrong passphrase) | unlocked */
    public function secretsState(): string
    {
        if (! $this->exists(PackageFormat::SECRETS)) {
            return 'absent';
        }

        return $this->secrets() === null ? 'locked' : 'unlocked';
    }

    /**
     * The package's secrets, null when it has none or the passphrase does not open them.
     *
     * @return array<string, mixed>|null
     */
    public function secrets(): ?array
    {
        if ($this->secrets === null) {
            $this->secrets = false;
            $envelope = $this->file(PackageFormat::SECRETS);
            if ($envelope !== null && $this->choices->passphrase !== null) {
                try {
                    $decoded = json_decode(PassphraseBox::open((string) file_get_contents($envelope), $this->choices->passphrase), true);
                    $this->secrets = is_array($decoded) ? $decoded : false;
                } catch (PackageException) {
                    $this->secrets = false;
                }
            }
        }

        return $this->secrets === false ? null : $this->secrets;
    }

    public function secret(string $group, string $key): mixed
    {
        return $this->secrets()[$group][$key] ?? null;
    }

    // ── Regions ──────────────────────────────────────────────────────────

    /** @return Collection<int, KnativeRegion> the regions the organization can use */
    public function targetRegions(): Collection
    {
        return $this->targetRegions ??= KnativeRegion::query()->orderBy('name')->get()
            ->filter(fn (KnativeRegion $r) => app(RegionPolicy::class)->allows($r, RegionPolicy::PROJECT_CREATE))
            ->values();
    }

    /** @return list<array<string, mixed>> regions the package's entries ran in */
    public function sourceRegions(): array
    {
        return array_values(array_filter((array) ($this->json(PackageFormat::ORGANIZATION, [])['regions'] ?? []), 'is_array'));
    }

    /**
     * The target region of a source region: the user's choice, else the region with the same id
     * (an import into the same installation), else one with the same name, else the
     * organization's default region (data residency), else the only region there is.
     */
    public function regionFor(?string $sourceRegionId): ?KnativeRegion
    {
        if ($sourceRegionId === null || $sourceRegionId === '') {
            return null;
        }

        return $this->regionMapping()[$sourceRegionId] ?? null;
    }

    /** @return array<string, ?KnativeRegion> */
    public function regionMapping(): array
    {
        if ($this->regionMapping !== null) {
            return $this->regionMapping;
        }

        $targets = $this->targetRegions();
        $byId = $targets->keyBy(fn (KnativeRegion $r) => (string) $r->id);
        $default = app(RegionPolicy::class)->defaultRegionId();

        $mapping = [];
        foreach ($this->sourceRegions() as $source) {
            $id = (string) ($source['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $chosen = $this->choices->regionMap[$id] ?? null;
            $mapping[$id] = match (true) {
                $chosen !== null => $byId->get($chosen),
                $byId->has($id) => $byId->get($id),
                default => $targets->first(fn (KnativeRegion $r) => $r->name === ($source['name'] ?? null))
                    ?? ($default !== null ? $byId->get($default) : null)
                    ?? ($targets->count() === 1 ? $targets->first() : null),
            };
        }

        return $this->regionMapping = $mapping;
    }

    // ── Names ────────────────────────────────────────────────────────────

    /** The name a source project gets: the user's rename, else its own. */
    public function projectName(array $project): string
    {
        $rename = $this->choices->renames[(string) ($project['id'] ?? '')] ?? null;

        return $rename !== null && $rename !== '' ? $rename : (string) ($project['name'] ?? '');
    }

    public function skipsProject(string $sourceId): bool
    {
        return in_array($sourceId, $this->choices->skipProjects, true);
    }

    /** A project of this organization already uses the slug the name gives. */
    public function slugTaken(string $name, ?string $exceptProjectId = null): bool
    {
        return Project::query()->where('slug', Project::slugFor($name))
            ->when($exceptProjectId, fn ($q) => $q->whereKeyNot($exceptProjectId))
            ->exists();
    }

    /** "<name>-2", "-3", … — the first name whose slug is free. */
    public function freeName(string $name, int $maxLength = Project::MAX_SLUG_LENGTH): string
    {
        for ($i = 2; $i < 100; $i++) {
            $suffix = '-'.$i;
            $candidate = mb_substr($name, 0, max(1, $maxLength - strlen($suffix))).$suffix;
            if (! $this->slugTaken($candidate)) {
                return $candidate;
            }
        }

        return mb_substr($name, 0, $maxLength - 7).'-'.strtolower(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    // ── Entries done ─────────────────────────────────────────────────────

    /** The target id of an entry created before (this apply or an earlier one), else null. */
    public function mapped(string $section, string $sourceKey): ?string
    {
        $item = $this->items()[$section.'|'.$sourceKey] ?? null;

        return $item && in_array($item->status, [OrgTransferItem::CREATED, OrgTransferItem::EXISTS], true) ? $item->target_id : null;
    }

    /** The entry needs no further attempt (created, existed, skipped or left to a manual step). */
    public function settled(string $section, string $sourceKey): bool
    {
        $item = $this->items()[$section.'|'.$sourceKey] ?? null;

        return $item !== null && in_array($item->status, OrgTransferItem::SETTLED, true);
    }

    public function record(string $section, string $sourceKey, ?string $name, string $status, ?string $targetId = null, ?string $message = null): void
    {
        $this->items();
        $item = OrgTransferItem::query()->updateOrCreate(
            ['org_transfer_id' => $this->transfer->id, 'section' => $section, 'source_key' => mb_substr($sourceKey, 0, 191)],
            ['name' => $name !== null ? mb_substr($name, 0, 250) : null, 'target_id' => $targetId, 'status' => $status, 'message' => $message !== null ? mb_substr($message, 0, 2000) : null],
        );
        $this->items[$section.'|'.$item->source_key] = $item;
    }

    /** @return array<string, OrgTransferItem> */
    private function items(): array
    {
        if ($this->items === null) {
            $this->items = [];
            foreach (OrgTransferItem::query()->where('org_transfer_id', $this->transfer->id)->get() as $item) {
                $this->items[$item->section.'|'.$item->source_key] = $item;
            }
        }

        return $this->items;
    }

    // ── Report ───────────────────────────────────────────────────────────

    /** @param list<string> $items */
    public function manual(string $code, string $message, array $items = []): void
    {
        foreach ($this->manualSteps as &$step) {
            if ($step['code'] === $code) {
                $step['items'] = array_values(array_unique(array_merge($step['items'], $items)));

                return;
            }
        }
        unset($step);
        $this->manualSteps[] = ['code' => $code, 'message' => $message, 'items' => array_values(array_unique($items))];
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function progress(string $phase, int $from, int $to, int|float $done, int|float $total, ?string $message = null): void
    {
        $this->assertNotCancelled();
        $this->progress?->step($this->transfer, $phase, $from, $to, $done, $total, $message);
    }

    /** @throws TransferCancelled */
    public function assertNotCancelled(): void
    {
        $status = OrgTransfer::query()->whereKey($this->transfer->id)->value('status');
        if ($status === OrgTransfer::STATUS_CANCELLED || $status === null) {
            throw new TransferCancelled;
        }
    }

    public function userId(): ?string
    {
        return $this->user !== null ? (string) $this->user->getKey() : null;
    }
}
