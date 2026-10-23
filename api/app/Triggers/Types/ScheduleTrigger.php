<?php

namespace App\Triggers\Types;

use App\Models\ProjectTrigger;
use App\Rules\CronSchedule;
use App\Support\OrgTimezone;
use App\Triggers\GatewayContext;

/**
 * A cron schedule in a time zone. Alone it runs as the project's CronJob, as schedule projects
 * always did (TriggerPlan::cronJob(), spec.timeZone); next to other triggers it is a source of the
 * event gateway: each tick is a CloudEvent (dev.runr.schedule.tick) POSTed to the project's
 * Knative Service — no CronJob pod.
 *
 * config.timezone is an IANA zone; without one the schedule follows the organization's time zone
 * (OrgTimezone, the zone of the timed warmer): the deploy renders the zone of the moment and a
 * change of the organization's zone reaches the deployed schedules at once
 * (App\Triggers\ScheduleTimezones). Schedules that existed before time zones keep UTC (tenant
 * migration 0090).
 */
class ScheduleTrigger extends GatewayTriggerType
{
    /** Marks a rendered schedule that follows the organization's zone (gateway.json source, CronJob annotation). */
    public const FROM_ORGANIZATION = 'organization';

    public function key(): string
    {
        return 'schedule';
    }

    protected function configRules(): array
    {
        return [
            'cron' => ['required', 'string', new CronSchedule],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone:all_with_bc'],
        ];
    }

    public function normalize(array $config): array
    {
        $normalized = ['cron' => trim((string) ($config['cron'] ?? ''))];
        $zone = is_string($config['timezone'] ?? null) ? trim($config['timezone']) : '';
        if ($zone !== '') {
            $normalized['timezone'] = $zone;
        }

        return self::keepDelivery($config, $normalized);
    }

    public function runtimeTrigger(): string
    {
        return 'schedule';
    }

    public function entryPoint(): string
    {
        return 'schedule';
    }

    public function gatewayEntry(ProjectTrigger $trigger, GatewayContext $context): ?array
    {
        $source = ['cron' => (string) $trigger->setting('cron'), 'timezone' => self::timezoneOf($trigger)];
        if (self::followsOrganization($trigger)) {
            // ScheduleTimezones finds the entries to update when the organization's zone changes
            $source['timezone_from'] = self::FROM_ORGANIZATION;
        }

        return ['kind' => 'schedule', 'source' => $source];
    }

    /** The zone the schedule runs in: its own, else the organization's. */
    public static function timezoneOf(ProjectTrigger $trigger): string
    {
        $zone = $trigger->setting('timezone');

        return OrgTimezone::isValid($zone) ? (string) $zone : OrgTimezone::get();
    }

    /** Whether the schedule has no zone of its own and runs in the organization's. */
    public static function followsOrganization(ProjectTrigger $trigger): bool
    {
        return ! OrgTimezone::isValid($trigger->setting('timezone'));
    }

    public function present(ProjectTrigger $trigger): array
    {
        return [
            'effective_timezone' => self::timezoneOf($trigger),
            'timezone_source' => self::followsOrganization($trigger) ? self::FROM_ORGANIZATION : 'trigger',
        ];
    }

    public function fields(): array
    {
        return [
            $this->field('cron', required: true),
            $this->field('timezone'),
        ];
    }
}
