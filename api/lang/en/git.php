<?php

// Git-native deployment (App\Http\Controllers\Api\Git*, App\Services\GitDeploy)
return [
    'forbidden' => 'You do not have the :permission permission for this project.',
    'already_connected' => 'This project is already connected to a repository. Disconnect it first.',
    'repository_unreachable' => 'The repository cannot be read with these credentials: :error',
    'webhook_failed' => 'The webhook could not be registered on the repository: :error',
    'provider_unreachable' => 'The provider did not answer: :error',
    'provider_unknown' => 'This git provider is not available.',
    'provider_in_use' => 'This provider is used by :count connected project(s). Disconnect them first.',
    'private_key_invalid' => 'The private key is not a valid PEM key.',
    'installation_unknown' => 'This GitHub App installation is not linked to your organization. Install the app first.',
    'oauth_expired' => 'The GitLab sign-in expired. Sign in again.',
    'oauth_not_configured' => 'This GitLab instance has no OAuth application. Use an access token.',
    'token_required' => 'An access token is required.',
    'nothing_to_redeploy' => 'The deploy branch has no commit to deploy yet.',
    'preview_not_redeployable' => 'Only a ready or failed preview can be deployed again.',
    'policy_pending' => 'Waiting for the security checks of :version (:reasons); the deploy continues when they finish.',
    'policy_blocked_hint' => 'Fix the findings and push again, or deploy the version from the console with a policy override and a reason.',
];
