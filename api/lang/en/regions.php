<?php

// Regions: what region:reconcile left (App\Services\RegionReconcile\ReconcileRecord, docs/upgrade.md)
return [
    'reconcile' => [
        'unfinished_title' => 'Regions are not at RunR :version yet',
        'unfinished_body' => 'region:reconcile did not finish (run :run, attempt :attempt): :regions region(s) were left behind. The region status (Platform → Knative Regions → the region → General) names the steps and the log of the Job runr-region-reconcile-* of the upgrade has the details; fix what they name and run php artisan region:reconcile.',
        'unreachable_title' => 'Some region clusters did not answer',
        'unreachable_body' => 'region:reconcile could not reach :regions region(s) (run :run). They keep what they had; run php artisan region:reconcile once their cluster answers.',
    ],
];
