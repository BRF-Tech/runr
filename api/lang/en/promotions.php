<?php

// Notifications of promotions between environments (docs/environments.md#notifications)
return [
    'pending_approval_title' => ':project: v:version waits for your approval to go to :target',
    'pending_approval_body' => ':requester asks to promote v:version of :project from :source to :target.',
    'approved_title' => ':project: the promotion of v:version to :target was approved',
    'approved_body' => ':decider approved your promotion of v:version of :project from :source to :target; it deploys now.',
    'rejected_title' => ':project: the promotion of v:version to :target was rejected',
    'rejected_body' => ':decider rejected your promotion of v:version of :project from :source to :target.',
    'deployed_title' => ':project: v:version runs in :target',
    'deployed_body' => 'Your promotion of v:version of :project from :source to :target is deployed.',
    'failed_title' => ':project: the promotion of v:version to :target failed',
    'failed_body' => 'Your promotion of v:version of :project from :source to :target failed.',
    'message' => 'Message: :text',
    'comment' => 'Comment: :text',
    'reason' => 'Reason: :text',
    'open' => 'Open',
    'someone' => 'Someone',
];
