<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A RunR release the patch feed announces ("platform" entries): platform administrators of an
 * install on an older version see "RunR <version> available (<level>): <reason>".
 *
 * @property string $id
 * @property string $version
 * @property string $level
 * @property array<string, string> $reason
 * @property string|null $url
 * @property Carbon|null $published_at
 * @property bool $in_feed
 */
class PlatformAdvisory extends Model
{
    use HasUlids, UsesCentralConnection;

    protected $fillable = ['version', 'level', 'reason', 'url', 'published_at', 'in_feed'];

    protected $casts = [
        'reason' => 'array',
        'published_at' => 'datetime',
        'in_feed' => 'boolean',
    ];

    /** Whether this release is newer than the running one (a dev build is never behind). */
    public function isNewerThan(string $running): bool
    {
        $running = ltrim(trim($running), 'vV');
        if ($running === '' || ! preg_match('/^\d+\.\d+/', $running)) {
            return false;
        }

        return version_compare(ltrim($this->version, 'vV'), $running, '>');
    }

    public function reasonText(?string $locale = null): string
    {
        $reason = (array) ($this->reason ?? []);
        $locale ??= app()->getLocale();

        return (string) ($reason[$locale] ?? $reason['en'] ?? (reset($reason) ?: ''));
    }

    public function toApi(): array
    {
        return [
            'version' => $this->version,
            'level' => $this->level,
            'reason' => $this->reasonText(),
            'url' => $this->url,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
