<?php

// Project environments and promotion (docs/environments.md), rollback with the config (docs/versioning.md)
return [
    'unknown' => 'The project has no environment ":name".',
    'deleted' => 'Environment :name was deleted.',
    'name_invalid' => 'An environment name is a lowercase DNS label: letters, digits and hyphens, starting with a letter, at most :max characters.',
    'name_reserved' => '":name" is reserved: the project\'s own workload, its previews and its resources use it.',
    'name_taken' => 'The project already has an environment named ":name".',
    'workload_taken' => 'The workload name :workload is already used by another project or environment; choose another name.',
    'project_without_region' => 'The project has no region: give the environment one.',
    'region_unavailable' => 'That region cannot run environments.',
    'region_live' => 'The environment runs a deployment: delete it (or the environment) before moving it to another region.',
    'region_endpoints' => 'The environment has a gateway route or private database connections in its region: remove them before moving it to another region.',
    'primary_not_deletable' => 'The production environment is the project itself: delete the project instead.',
    'primary_secrets' => 'Secrets of the production environment are the project\'s secret variables.',
    'branch_invalid' => 'That is not a valid branch name.',
    'branch_is_production' => 'Branch :branch deploys production (the project\'s deploy branch).',
    'branch_taken' => 'Branch :branch already deploys environment :environment.',
    'capacity' => 'The environment does not fit its region: :problems',
    'quota' => 'The organization quota leaves no room for the environment: :message',
    'promote_same' => 'An environment cannot be promoted to itself.',
    'promote_nothing_live' => 'Nothing runs in :environment: deploy a version there first.',
    'promote_last' => ':environment is the last environment of the pipeline: name the environment to promote to.',
    'promote_image_changed' => 'v:version was built again since the promotion was requested: request the promotion again to deploy the new image.',
    'self_approval' => 'A promotion is approved by someone other than the one who requested it.',
    'promotion_not_open' => 'The promotion is :status: it cannot be changed any more.',
    'rollback_version_gone' => 'The version of that deployment no longer exists.',
];
