<?php

/*
|--------------------------------------------------------------------------
| Git-native deployment (docs/git-deploy.md)
|--------------------------------------------------------------------------
| Every project has a git remote on the console domain (smart HTTP, served by `git http-backend`
| behind the API): https://<console>/git/<organization>/<project>.git. Projects can also be
| connected to a repository on GitHub (GitHub App), GitLab or Forgejo/Gitea: pushes to the deploy
| branch deploy, pull/merge requests get preview environments.
*/

return [
    // false: the /git endpoints answer 404 and webhooks are refused
    'enabled' => (bool) env('GIT_DEPLOY_ENABLED', true),

    // Bare repositories of the project remotes. Empty: <storage>/git-remotes
    'storage_path' => env('GIT_REMOTES_PATH'),

    // Path segment before the project in remote URLs of a single-organization install
    // (multi-tenant installs use the organization's slug)
    'owner' => env('GIT_REMOTE_OWNER', 'runr'),

    'git_binary' => env('GIT_BINARY', 'git'),

    // Largest pack a push may send (receive.maxInputSize); keep it below PHP post_max_size
    'max_push_mb' => (int) env('GIT_MAX_PUSH_MB', 50),

    // Limits of a pushed tree imported as a version: function code ends up in a ConfigMap, so a
    // version holds at most 1,000,000 bytes of names + contents (the CLI's limit too); lower
    // values tighten it
    'max_import_files' => (int) env('GIT_MAX_IMPORT_FILES', 1000),
    'max_import_bytes' => (int) env('GIT_MAX_IMPORT_BYTES', 1000000),

    // Seconds a git http-backend process may run
    'backend_timeout' => (int) env('GIT_BACKEND_TIMEOUT', 300),

    // Calls to the GitHub / GitLab / Forgejo APIs
    'http_timeout' => (int) env('GIT_PROVIDER_HTTP_TIMEOUT', 20),

    // Name of the commit status / check RunR reports (GitHub context, GitLab name)
    'status_context' => env('GIT_STATUS_CONTEXT', 'runr'),

    // URL the browser and the providers reach the console at; empty: FRONTEND_URL / APP_URL
    'console_url' => env('GIT_CONSOLE_URL'),

    // Base URL of the webhook endpoints registered with providers; empty: the console URL
    'webhook_base_url' => env('GIT_WEBHOOK_BASE_URL'),

    'previews' => [
        // Defaults of a project's preview settings
        'limit' => (int) env('GIT_PREVIEW_LIMIT', 3),
        'ttl_hours' => (int) env('GIT_PREVIEW_TTL_HOURS', 72),
        // Upper bounds a project setting may use
        'max_limit' => (int) env('GIT_PREVIEW_MAX_LIMIT', 20),
        'max_ttl_hours' => (int) env('GIT_PREVIEW_MAX_TTL_HOURS', 720),
    ],

    // Failed sign-ins on the git endpoint per client IP and minute
    'auth_attempts_per_minute' => (int) env('GIT_AUTH_ATTEMPTS_PER_MINUTE', 30),
];
