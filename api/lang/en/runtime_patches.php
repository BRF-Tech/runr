<?php

// Runtime patches and end-of-life reminders (docs/runtimes.md#runtime-patches)
return [
    'levels' => [
        'routine' => 'Routine',
        'security' => 'Security',
        'critical' => 'Critical',
        'zero-day' => 'Zero-day',
    ],

    'applying' => 'Redeploying the live version onto :tag.',
    'queued' => 'v:version is built again on runtime patch :tag; it deploys when the build is ready.',
    'queued_rollback' => 'v:version is built again on the runtime image it ran before (rollback of a failed patch migration).',

    'errors' => [
        'not_applicable' => 'Runtime patches apply to code projects on a RunR runtime only.',
        'project_stopped' => 'The project is stopped: resume it first.',
        'no_patch' => 'There is no runtime patch for this runtime version.',
        'not_deployed' => 'Nothing of the project is live: the next deploy uses the newest patch (or the pin).',
        'already_running' => 'The project already runs :tag.',
        'migration_running' => 'A patch migration of the project is still running.',
        'not_pinnable' => 'A project can be pinned to one of the :keep newest patches of its runtime version only.',
        'no_deadline' => 'Only critical and zero-day patches have a deadline.',
    ],

    'notify' => [
        'open' => 'Open in RunR',
        'patch_title' => ':level runtime patch :tag for :runtime',
        'patch_title_admin' => '{1} :level runtime patch :tag: :count project runs behind it|[2,*] :level runtime patch :tag: :count projects run behind it',
        'patch_body' => ":reason\nCVEs: :cves\nProjects: :projects",
        'patch_deadline' => 'Deadline: :deadline — projects still behind are redeployed onto the patch then (rolled back if the new revision does not become ready).',
        'migrated_title' => ':project now runs :tag',
        'migrated_body' => 'RunR redeployed :project onto runtime patch :tag (:runtime) at the patch\'s deadline.',
        'migration_failed_title' => 'Patch :tag could not be applied to :project',
        'migration_failed_body' => 'Redeploying :project onto runtime patch :tag (:runtime) failed: :reason',
        'migration_rolled_back_body' => 'Redeploying :project onto runtime patch :tag (:runtime) failed and was rolled back to the runtime image it ran before; the project is pinned there until you unpin it. Reason: :reason',
        'rollback_failed' => 'The rollback could not be started either: :error',
        'pin_dropped_title' => 'The pinned runtime patch of :project is gone',
        'pin_dropped_body' => ':project was pinned to :pin, which is no longer one of the newest patches of its runtime version. The pin was removed; it now runs :tag.',
        'feed_rejected_title' => 'Runtime patch feed refused (:status)',
        'feed_rejected_body' => "The runtime patch feed :feed was refused and ignored; RunR keeps the last good feed.\n:error",
        'eol_title' => '{1} :runtime reaches its end of life in a week|[2,*] :runtime reaches its end of life in :weeks weeks',
        'eol_body' => '{1} :runtime is end-of-life on :date (in a week): projects on it can no longer be deployed after that. Move them to a newer version: :projects|[2,*] :runtime is end-of-life on :date (in :weeks weeks): projects on it can no longer be deployed after that. Move them to a newer version: :projects',
        'alert_resolved' => 'No project of the organization runs behind :tag any more.',
    ],
];
