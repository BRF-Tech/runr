<?php

// Sandbox API messages (docs/sandbox-api.md)
return [
    'not_found' => 'Sandbox not found.',
    'not_running' => 'The sandbox is :status.',
    'cluster_error' => 'The cluster refused the request: :detail',
    'tenant_gone' => 'The organization no longer exists.',

    'region_unknown' => 'Region ":region" is not available for sandboxes.',
    'region_disabled' => 'Sandboxes are turned off in region :region.',
    'no_region' => 'No region with sandboxes enabled is available.',
    'region_gone' => 'The sandbox\'s region is no longer available.',

    'no_class' => 'No sandbox class is available in region :region: install gVisor or Kata Containers there, or allow runc in the region\'s sandbox settings.',
    'class_not_allowed' => 'Sandbox class ":class" is not allowed here. Allowed: :allowed.',
    'runc_not_allowed' => 'runc can only be the default class when the region allows runc.',

    'custom_images_disabled' => 'Custom images are turned off; choose a runtime.',
    'version_unknown' => 'Runtime :runtime has no version ":version".',
    'cpu_invalid' => 'cpu must look like "500m", 0.5 or "1".',
    'memory_invalid' => 'memory must be MiB (512) or a quantity like "512Mi" / "1Gi".',
    'disk_invalid' => 'disk must be MiB (1024) or a quantity like "1Gi".',
    'cpu_range' => 'cpu must be between 50m and :maxm.',
    'memory_range' => 'memory must be between 64 and :max MiB.',
    'disk_range' => 'disk must be between 64 and :max MiB.',
    'ttl_range' => 'ttl_seconds must be between 10 and :max.',
    'env_key_invalid' => 'Environment variable name ":key" is not valid.',
    'base64_invalid' => 'The content is not valid base64.',
    'files_too_large' => 'The files are larger than :max bytes together.',
    'files_failed' => 'The sandbox files could not be written: :detail',

    'network_invalid' => 'network must be "none" or {"egress_allow": [...]}.',
    'network_entry_invalid' => 'Egress entry ":entry" is not an address, CIDR or host name (with an optional port).',
    'network_unresolvable' => 'Host name :host does not resolve.',
    'network_denied_range' => 'Egress entry ":entry" is a range sandboxes never reach (cloud metadata).',
    'network_too_many' => 'At most :max egress entries are allowed.',

    'quota_concurrent' => 'The organization already runs its maximum of :max sandboxes.',
    'quota_cpu' => 'The organization\'s sandbox CPU quota (:maxm, :usedm in use) is exhausted.',
    'quota_memory' => 'The organization\'s sandbox memory quota (:max MiB, :used MiB in use) is exhausted.',
    'quota_seconds' => 'The organization used its :max sandbox seconds of this month.',
    'quota_seconds_reached' => 'Stopped: the organization used its sandbox seconds of this month.',
    'quota_region' => 'The organization\'s quota in this region is exhausted.',
    'quota_pool' => 'Warm pools of the organization may hold at most :max sandboxes together.',

    'namespace_failed' => 'The sandbox namespace :namespace could not be prepared.',
    'policy_failed' => 'The network policy of namespace :namespace could not be applied.',
    'secret_failed' => 'The env Secret of the sandbox could not be written in namespace :namespace.',
    'pod_failed' => 'The sandbox pod could not be created.',
    'pod_gone' => 'The sandbox pod is gone.',
    'start_timeout' => 'The sandbox did not start in time.',
    'start_timeout_event' => 'Last pod event: :reason: :message',
    'sandbox_create_failed' => 'The container runtime could not create the sandbox (:count attempts): :error',
    'start_failed' => 'The sandbox could not start: :error',

    'exec_cmd_required' => 'cmd must be a non-empty string or a list of arguments.',
    'language_unknown' => 'Language ":language" is not supported. Supported: :known, or a runtime with an exec command.',
    'ws_code_too_large' => 'Code sent over the exec WebSocket is limited to 120 KB; use POST /run for larger code.',
    'ws_missing_token' => 'Missing token.',
    'ws_session_expired' => 'Exec session not found or expired.',
    'ws_start_failed' => 'The command could not be started.',

    'path_invalid' => 'Path ":path" must be absolute.',
    'path_root' => 'The root directory cannot be deleted.',
    'mode_invalid' => 'mode must be an octal file mode such as "0644".',
    'file_too_large' => 'The file is larger than the limit of :max bytes.',
    'file_op_failed' => 'The file operation on :path failed.',

    'process_start_failed' => 'The process could not be started: :detail',
    'process_unknown' => 'Process not found.',
    'signal_invalid' => 'signal must be one of :signals.',

    'pool_unknown' => 'Warm pool not found.',
    'pool_size_exceeds' => 'The pool\'s sandbox size is above the region\'s per-sandbox limits.',
    'pool_start_timeout' => 'A warm sandbox did not start in time.',

    'platform_admin_required' => 'Only platform administrators can change this.',
];
