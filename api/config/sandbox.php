<?php

/*
|--------------------------------------------------------------------------
| Sandbox API (docs/sandbox-api.md)
|--------------------------------------------------------------------------
|
| Isolated execution of untrusted code: plain pods (not Knative) in a namespace per organization,
| under the region's strongest sandbox RuntimeClass (Kata > gVisor; runc only where an admin
| allows it), default-deny networking, no service-account token, non-root, seccomp RuntimeDefault,
| no capabilities. Every value below is the platform default; regions (sandbox_region_settings)
| and organizations (sandbox_quotas) narrow it.
|
*/

return [

    'enabled' => (bool) env('SANDBOX_API_ENABLED', true),

    // Namespace of an organization's sandboxes in a region: "<prefix>-<tenant slug>", "<prefix>-default"
    // on a single-tenant install / central context.
    'namespace_prefix' => env('SANDBOX_NAMESPACE_PREFIX', 'runr-sbx'),

    // Runtime of a sandbox created without runtime / image
    'default_runtime' => env('SANDBOX_DEFAULT_RUNTIME', 'python'),

    // `image` instead of a runtime: any image (the isolation is the sandbox class, not the image)
    'allow_custom_images' => (bool) env('SANDBOX_ALLOW_CUSTOM_IMAGES', true),

    'defaults' => [
        'cpu_m' => (int) env('SANDBOX_DEFAULT_CPU_M', 500),
        'memory_mb' => (int) env('SANDBOX_DEFAULT_MEMORY_MB', 512),
        'disk_mb' => (int) env('SANDBOX_DEFAULT_DISK_MB', 1024),
        'pids_limit' => (int) env('SANDBOX_DEFAULT_PIDS', 256),
        'ttl_seconds' => (int) env('SANDBOX_DEFAULT_TTL', 900),
        'idle_timeout_seconds' => (int) env('SANDBOX_DEFAULT_IDLE_TIMEOUT', 600),
        'read_only_root' => (bool) env('SANDBOX_DEFAULT_READ_ONLY_ROOT', true),
    ],

    // Per-sandbox ceilings (a region may lower them)
    'limits' => [
        'max_cpu_m' => (int) env('SANDBOX_MAX_CPU_M', 4000),
        'max_memory_mb' => (int) env('SANDBOX_MAX_MEMORY_MB', 8192),
        'max_disk_mb' => (int) env('SANDBOX_MAX_DISK_MB', 10240),
        'max_pids' => (int) env('SANDBOX_MAX_PIDS', 4096),
        'max_ttl_seconds' => (int) env('SANDBOX_MAX_TTL', 86400),
        'max_env' => 100,
        'max_files' => 200,
        'max_files_bytes' => (int) env('SANDBOX_MAX_CREATE_FILES_BYTES', 10 * 1024 * 1024),
        'max_metadata' => 50,
        'max_egress_rules' => 50,
    ],

    // Organization quotas when neither the organization nor the defaults row (sandbox_quotas) sets them. 0 = unlimited.
    'quota' => [
        'max_concurrent' => (int) env('SANDBOX_QUOTA_CONCURRENT', 20),
        'max_cpu_m' => (int) env('SANDBOX_QUOTA_CPU_M', 8000),
        'max_memory_mb' => (int) env('SANDBOX_QUOTA_MEMORY_MB', 16384),
        'max_sandbox_seconds_month' => (int) env('SANDBOX_QUOTA_SECONDS_MONTH', 0),
        'max_pool_size' => (int) env('SANDBOX_QUOTA_POOL_SIZE', 10),
    ],

    'start_timeout_seconds' => (int) env('SANDBOX_START_TIMEOUT', 120),

    // A starting pod (sandbox or warm pool pod) is read every start_poll_ms (100–200) for the first
    // start_poll_fast_seconds, then every second (SandboxService::startPollDelayUs())
    'start_poll_ms' => (int) env('SANDBOX_START_POLL_MS', 150),
    'start_poll_fast_seconds' => 30,

    'exec' => [
        'default_timeout' => (int) env('SANDBOX_EXEC_TIMEOUT', 60),
        'max_timeout' => (int) env('SANDBOX_EXEC_MAX_TIMEOUT', 3600),
        'max_output' => (int) env('SANDBOX_EXEC_MAX_OUTPUT', 10 * 1024 * 1024),
        'max_stdin' => (int) env('SANDBOX_EXEC_MAX_STDIN', 10 * 1024 * 1024),
        'ws_token_ttl' => 60,
        'kubectl' => env('SANDBOX_KUBECTL', 'kubectl'),
    ],

    'files' => [
        'max_upload_bytes' => (int) env('SANDBOX_MAX_UPLOAD_BYTES', 50 * 1024 * 1024),
        'max_download_bytes' => (int) env('SANDBOX_MAX_DOWNLOAD_BYTES', 50 * 1024 * 1024),
        'max_list_entries' => 10000,
    ],

    'run' => [
        'max_outputs_bytes' => (int) env('SANDBOX_RUN_MAX_OUTPUTS_BYTES', 10 * 1024 * 1024),
        'max_files_changed' => 1000,
        'output_dir' => '/output',
        'image_extensions' => ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp'],

        // Interpreters of POST /sandboxes/{id}/run. {file} is the code file. A runtime of the catalog
        // whose manifest runs an exec-mode command with {file} (and no build step) works as well.
        'languages' => [
            'python' => ['extension' => 'py', 'commands' => [['python3', '{file}'], ['python', '{file}']]],
            'javascript' => ['extension' => 'js', 'commands' => [['node', '{file}']]],
            'typescript' => ['extension' => 'ts', 'commands' => [['tsx', '{file}'], ['ts-node', '{file}'], ['node', '--experimental-strip-types', '{file}']]],
            'bash' => ['extension' => 'sh', 'commands' => [['bash', '{file}'], ['sh', '{file}']]],
            'sh' => ['extension' => 'sh', 'commands' => [['sh', '{file}']]],
            'php' => ['extension' => 'php', 'commands' => [['php', '{file}']]],
            'ruby' => ['extension' => 'rb', 'commands' => [['ruby', '{file}']]],
            'go' => ['extension' => 'go', 'commands' => [['go', 'run', '{file}']]],
        ],
        'aliases' => [
            'py' => 'python', 'python3' => 'python', 'node' => 'javascript', 'nodejs' => 'javascript',
            'js' => 'javascript', 'ts' => 'typescript', 'shell' => 'bash', 'golang' => 'go', 'rb' => 'ruby',
        ],
        // Language a sandbox's runtime defaults to
        'runtime_languages' => ['python' => 'python', 'pytorch' => 'python', 'tensorflow' => 'python', 'nodejs' => 'javascript', 'php' => 'php', 'go' => 'go', 'bash' => 'bash'],
    ],

    'pool' => [
        // A warm pod older than this is replaced (it keeps the pod's activeDeadlineSeconds headroom)
        'max_age_seconds' => (int) env('SANDBOX_POOL_MAX_AGE', 3600),
        'max_pool_size' => 50,
        // A pod a refill started becomes warm as soon as it runs; after this many seconds the
        // next refill (every minute) takes over
        'promote_wait_seconds' => 60,
    ],

    // Unix UIDs of sandboxes: every sandbox runs as a UID of its own from this range, so per-UID
    // limits (RLIMIT_NPROC) apply per sandbox under runc as well
    'uid_range' => [(int) env('SANDBOX_UID_MIN', 100000), (int) env('SANDBOX_UID_MAX', 165535)],

    // Grace on top of the max TTL before the kubelet's activeDeadlineSeconds stops a pod the API lost
    'deadline_grace_seconds' => 300,

    // Terminated sandboxes (rows, processes, events) are deleted after this many days
    'retention_days' => (int) env('SANDBOX_RETENTION_DAYS', 30),

    // Address ranges never reachable from a sandbox, even with an egress allowlist entry that covers them
    'egress_always_denied' => ['169.254.0.0/16', 'fd00:ec2::/32'],

];
