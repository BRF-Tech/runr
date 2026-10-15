<?php

namespace App\Services\Concerns;

use App\Events\PlatformEvent;
use App\Models\KnativeRegion;

trait KnativeLogTrait
{
    /**
     * Append a timestamped line to the region's install log and broadcast progress.
     */
    private function appendLog(KnativeRegion $region, string $message): void
    {
        $region->refresh();
        $log = $region->knative_status_message ?? '';
        $time = now()->format('H:i:s');
        $log .= "[{$time}] {$message}\n";

        $region->update(['knative_status_message' => $log]);

        event(new PlatformEvent('install.progress', [
            'region_id' => $region->id,
            'component' => 'knative',
            'message' => $log,
        ]));
    }

    /**
     * Append a timestamped line to a component's install log and broadcast progress.
     */
    private function appendComponentLog(KnativeRegion $region, string $component, string $message): void
    {
        $region->refresh();
        $field = "knative_{$component}_status_message";
        $log = $region->$field ?? '';
        $time = now()->format('H:i:s');
        $log .= "[{$time}] {$message}\n";

        $region->update([$field => $log]);

        event(new PlatformEvent('install.progress', [
            'region_id' => $region->id,
            'component' => $component,
            'message' => $log,
        ]));
    }

    /**
     * Copy a component's log into the main knative_status_message.
     */
    private function aggregateLog(KnativeRegion $region, string $component): void
    {
        $region->refresh();
        $field = "knative_{$component}_status_message";
        $componentLog = $region->$field ?? '';
        if ($componentLog) {
            $log = $region->knative_status_message ?? '';
            $log .= $componentLog;
            $region->update(['knative_status_message' => $log]);
        }
    }
}
