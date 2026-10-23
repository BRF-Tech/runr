<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One patch tag of a runtime version from the signed patch feed (docs/runtimes.md#runtime-patches):
 * exec-php:8.3.36-r1 of php 8.3, with why it came (level, reason, CVEs, upstream release) and — for
 * critical and zero-day patches — until when projects may stay on an older one. Central: every
 * organization runs on the same images.
 *
 * @property string $id e.g. "php-8.3-8.3.36-r1"
 * @property string $runtime
 * @property string $version
 * @property string $tag
 * @property string $image repository, without tag ("ghcr.io/brf-tech/runr/exec-php")
 * @property string $digest "sha256:…"
 * @property string $level routine | security | critical | zero-day
 * @property array<string, string> $reason {en, tr}
 * @property list<string>|null $cves
 * @property string|null $url
 * @property string|null $upstream
 * @property Carbon $published_at
 * @property Carbon|null $deadline_at
 * @property Carbon|null $deadline_extended_to
 * @property string|null $deadline_extended_by
 * @property bool $in_feed
 * @property Carbon|null $removed_at
 * @property Carbon|null $announced_at
 */
class RuntimePatch extends Model
{
    use UsesCentralConnection;

    /** The API document's fields of toApi() (the controllers' #[Response] attributes) */
    public const OPENAPI_FIELDS = 'id: string, runtime: string, version: string, tag: string, image: string, digest: string, level: "routine"|"security"|"critical"|"zero-day", reason: string, reasons: array<string, string>, cves: list<string>, url: string|null, upstream: string|null, published_at: string, deadline_at: string|null, deadline_extended: bool, in_feed: bool';

    public const ROUTINE = 'routine';

    public const SECURITY = 'security';

    public const CRITICAL = 'critical';

    public const ZERO_DAY = 'zero-day';

    /** Levels from the least to the most urgent */
    public const LEVELS = [self::ROUTINE, self::SECURITY, self::CRITICAL, self::ZERO_DAY];

    /** Levels with a deadline: RunR moves the projects that are still behind when it passes */
    public const DEADLINE_LEVELS = [self::CRITICAL, self::ZERO_DAY];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'runtime', 'version', 'tag', 'image', 'digest', 'level', 'reason', 'cves', 'url',
        'upstream', 'published_at', 'deadline_at', 'deadline_extended_to', 'deadline_extended_by',
        'in_feed', 'removed_at', 'announced_at',
    ];

    protected $casts = [
        'reason' => 'array',
        'cves' => 'array',
        'published_at' => 'datetime',
        'deadline_at' => 'datetime',
        'deadline_extended_to' => 'datetime',
        'in_feed' => 'boolean',
        'removed_at' => 'datetime',
        'announced_at' => 'datetime',
    ];

    /** The rank of a level (0 routine … 3 zero-day); unknown levels count as routine. */
    public static function rank(?string $level): int
    {
        $rank = array_search($level, self::LEVELS, true);

        return $rank === false ? 0 : (int) $rank;
    }

    /** The more urgent of two levels. */
    public static function higher(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return self::rank($b) > self::rank($a) ? $b : $a;
    }

    public function hasDeadline(): bool
    {
        return in_array($this->level, self::DEADLINE_LEVELS, true);
    }

    /** The deadline that counts: a platform administrator's extension, else the feed's. */
    public function effectiveDeadline(): ?Carbon
    {
        if (! $this->hasDeadline()) {
            return null;
        }

        return $this->deadline_extended_to ?? $this->deadline_at;
    }

    public function isOverdue(): bool
    {
        $deadline = $this->effectiveDeadline();

        return $deadline !== null && $deadline->isPast();
    }

    /** The reason in $locale, else English, else whatever the feed gave. */
    public function reasonText(?string $locale = null): string
    {
        $reason = (array) ($this->reason ?? []);
        $locale ??= app()->getLocale();

        return (string) ($reason[$locale] ?? $reason['en'] ?? (reset($reason) ?: ''));
    }

    /** The image by digest: what a pinned workload runs. */
    public function imageRef(): string
    {
        return $this->image.'@'.$this->digest;
    }

    /** API shape (projects, the Patches page, MCP, CLI). */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'runtime' => $this->runtime,
            'version' => $this->version,
            'tag' => $this->tag,
            'image' => $this->image,
            'digest' => $this->digest,
            'level' => $this->level,
            'reason' => $this->reasonText(),
            'reasons' => (array) ($this->reason ?? []),
            'cves' => (array) ($this->cves ?? []),
            'url' => $this->url,
            'upstream' => $this->upstream,
            'published_at' => $this->published_at->toIso8601String(),
            'deadline_at' => $this->effectiveDeadline()?->toIso8601String(),
            'deadline_extended' => $this->deadline_extended_to !== null,
            'in_feed' => $this->in_feed,
        ];
    }
}
