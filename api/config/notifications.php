<?php

/*
|--------------------------------------------------------------------------
| In-app notifications (the console's notification centre)
|--------------------------------------------------------------------------
|
| The daily prune (notification-retention, App\Services\Notifications\NotificationRetention)
| deletes notifications older than retention_days for good — read or not, deleted or not, a
| user's own and the shared ones with every member's read and deleted state of them — in the
| central schema and in every organization's schema. It also removes the notifications and the
| state rows of users that no longer exist, whatever the retention.
|
*/

return [
    // Days a notification is kept after it was created; 0 keeps every notification
    'retention_days' => max(0, (int) env('RUNR_NOTIFICATION_RETENTION_DAYS', 90)),
];
