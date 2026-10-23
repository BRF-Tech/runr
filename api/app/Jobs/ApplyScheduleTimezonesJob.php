<?php

namespace App\Jobs;

use App\Services\GitDeploy\TenantContext;
use App\Support\OrgTimezone;
use App\Triggers\ScheduleTimezones;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After an organization's time zone changed (PATCH /api/settings `timezone`): the deployed
 * schedule triggers that follow it run in the new zone at once (ScheduleTimezones). $tenantId:
 * the organization whose setting changed. Without one (the platform's own setting, or a
 * single-organization install) the central schema and every organization without a zone of its
 * own, which inherits the platform's.
 */
class ApplyScheduleTimezonesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    public int $timeout = 600;

    public function __construct(public ?string $tenantId = null) {}

    /** @return list<string> what changed */
    public function handle(ScheduleTimezones $zones): array
    {
        if ($this->tenantId !== null) {
            return TenantContext::run($this->tenantId, fn () => $zones->apply(OrgTimezone::get()));
        }

        $changed = $zones->apply(OrgTimezone::get());
        if (TenantContext::enabled()) {
            TenantContext::each(function () use ($zones, &$changed) {
                array_push($changed, ...$zones->apply(OrgTimezone::get()));
            });
        }

        return $changed;
    }

    /** Dispatch for the organization whose setting just changed (the current context). */
    public static function dispatchForCurrent(): void
    {
        self::dispatch(TenantContext::currentId());
    }
}
