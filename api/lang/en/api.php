<?php

return [

    // ── Auth ──
    'auth' => [
        'login_failed' => 'Invalid username/email or password.',
        'logged_out' => 'Logged out.',
    ],

    // ── User ──
    'user' => [
        'updated' => 'User updated.',
        'cannot_delete_self' => 'Cannot delete yourself.',
        'deleted' => 'User deleted.',
        '2fa_not_enabled' => '2FA is not enabled for this user.',
        '2fa_reset' => '2FA has been reset.',
    ],

    // ── API Token ──
    'token' => [
        'not_found' => 'Token not found.',
        'deleted' => 'Token deleted.',
    ],

    // ── Region (Knative) ──
    'region' => [
        'demo_not_modifiable' => 'Demo region cannot be modified.',
        'capacity_updated' => 'Capacity settings updated.',
        'capacity_platform_managed' => 'The capacity of a platform region is managed by the platform administrators; your organization quota applies.',
        'platform_region_read_only' => 'Platform regions are managed by the platform administrators.',
        'region_admin_required' => 'Only the tenant owner or a tenant admin can change this region.',
        'platform_admin_required' => 'Only platform administrators can change regions.',
        'kubeconfig_missing' => 'Kubeconfig not configured.',
        'kubeconfig_not_found' => 'Kubeconfig not found.',
        'install_in_progress' => 'Installation already in progress.',
        'uninstall_in_progress' => 'Uninstall already in progress.',
        'operator_required' => 'Operator must be installed first.',
        'serving_uninstall_first' => 'Serving must be uninstalled before Operator.',
        'operation_in_progress' => 'Operation already in progress.',
        'region_removed' => 'Region removed.',
        'workers_updated' => 'Workers updated.',
        'detection_failed' => 'Detection failed: :message',
        'namespace_check_failed' => 'Cannot check namespace: :message',
        'namespace_exists' => "Namespace ':namespace' already exists on the cluster.",
        'namespace_create_failed' => 'Failed to create namespace: :message',
        'versions_fetch_failed' => 'Cannot fetch versions: :message',
        'k8s_version_required' => 'Kubernetes 1.:required+ required. Detected: 1.:detected.',
        'connect_failed' => 'Cannot connect to cluster: :message',
        'storage_classes_failed' => 'Cannot list storage classes: :message',
        'function_tls_updated' => 'Function-domain TLS updated.',
        'function_tls_pem_invalid' => 'Must be a valid PEM :marker (plain PEM or base64-encoded).',
        'function_tls_console_unavailable' => 'Reusing the console certificate requires this region to run on the same cluster as the console.',
        'function_tls_console_not_configured' => 'The console does not have a custom certificate configured (ingress.tls.mode=custom).',
        'function_tls_sync_failed' => 'Failed to apply function-domain TLS: :message',
        'function_tls_store_conflict' => 'A Traefik TLSStore "default" already exists in namespace :namespace. Traefik uses one default TLSStore per cluster, so function-domain TLS is set only where that TLSStore lives.',
        'function_tls_store_replace_required' => 'A TLSStore "default" that RunR did not create exists in :namespace; it may also serve the console certificate. Send replace_existing: true to replace it.',
        'function_tls_key_mismatch' => 'The private key does not belong to the certificate.',
        'function_tls_expired' => 'The certificate expired on :date.',
        'function_tls_domain_mismatch' => 'The certificate does not cover *.:domain (certificate names: :names).',
        'function_tls_no_domain' => 'The region has no function domain (knative_domain) to check the certificate against.',
        'monitoring_url_invalid' => 'Must be an in-cluster Service URL of the region cluster: http(s)://<service>.<namespace>.svc[.cluster.local]:<port>[/path] (max. 255 characters).',
        'monitoring_endpoint_forbidden' => 'Only a platform administrator or the tenant owner can set a monitoring endpoint.',
    ],

    // ── Demo ──
    'demo' => [
        'cannot_seed_demo' => 'Cannot seed demo on a demo region.',
        'already_exists' => 'Demo projects already exist or are being created.',
        'already_removing' => 'Already removing.',
        'serving_not_ready' => 'Serving is not ready on this region.',
        'redis_not_ready' => 'Redis is not ready on this region.',
        'registry_not_ready' => 'Registry is not ready on this region.',
        'no_default_version' => 'No default version for runtime: :runtime',
    ],

    // ── Project ──
    'project' => [
        'eol_save' => 'This project uses an end-of-life runtime. Please update the runtime version before saving.',
        'eol_deploy' => 'This project uses an end-of-life runtime. Please update the runtime version before deploying.',
        'runtime_not_available' => "Runtime ':value' is not available.",
        'version_not_available' => 'Version :version is not available for :runtime.',
        'region_status' => 'This region is :status.',
        'region_no_kubeconfig' => 'This region has no kubeconfig configured.',
        'region_maintenance' => 'under maintenance',
        'region_inactive' => 'inactive',
        'version_not_ready' => 'This version is not ready for deployment. Build status: :status.',
        'version_pruned' => 'The build image of v:version was removed by registry retention and can no longer be deployed. Save the code again to build a new version.',
        'queue_requires_redis' => 'Queue trigger requires Redis configured on the selected region.',
        'slug_invalid' => 'The name must give a Kubernetes name that starts with a letter and contains only letters, digits and dashes (it gives ":slug").',
        'slug_too_long' => 'The name is too long: its Kubernetes name ":slug" has :length characters, at most :max are allowed.',
        'slug_taken' => 'Another project already uses the Kubernetes name ":slug"; choose a different name.',
        'cron_invalid' => 'The schedule must be a 5-field cron expression (minute hour day month weekday) or @hourly/@daily/@weekly/@monthly/@yearly; seconds, time zones and L/W/# are not supported.',
        'env_not_map' => 'Environment variables must be a map of NAME => value.',
        'env_too_many' => 'At most :max environment variables are allowed.',
        'env_key_invalid' => 'Invalid environment variable name ":key": use letters, digits and "_", not starting with a digit.',
        'env_key_reserved' => 'The environment variable ":key" is set by the platform and cannot be overridden.',
        'env_value_invalid' => 'The value of environment variable ":key" must be text.',
        'env_too_large' => 'Environment variables may be at most :max KiB in total.',
        'cpu_invalid' => 'CPU must be a Kubernetes quantity greater than zero, e.g. "250m" or "0.5".',
        'timeout_too_long' => 'The timeout may be at most :max seconds (request timeout of region :region).',
        'service_type_unsupported' => 'Only XaaS, FaaS and CaaS projects can be created, changed or deployed.',
    ],

    // ── Region capacity (WithinResourceLimit) ──
    // Region capacity admission (App\Services\Helpers\RegionCapacity)
    'resource_limit' => [
        'memory' => 'Memory',
        'always_on_exceeded' => ':resource limit exceeded on the always-on budget (no overcommit): this needs :needed, :available of the region capacity :total are free. Always-on workloads, Kata pod overhead, queue consumers, sandboxes and model services reserve real capacity.',
        'faas_exceeded' => ':resource limit exceeded on the FaaS overcommit budget: this needs :needed, :available of :total are free (region capacity :capacity × :factor, minus :reserved always-on).',
    ],

    // ── Project custom domains ──
    'domain' => [
        'invalid' => 'Enter a fully qualified host name such as api.example.com (lowercase letters, digits and dashes; internationalized names in their xn-- form).',
        'wildcard' => 'Wildcard domains are not supported; add each host name separately.',
        'taken' => 'This domain is already attached to a project.',
        'reserved' => 'This domain belongs to the platform (a region function domain or the console domain) and cannot be attached.',
        'no_knative_service' => 'Custom domains need a project that runs a Knative Service (container, function or code project).',
        'not_http' => 'Custom domains serve HTTP-triggered projects only; this project uses the ":trigger" trigger and has no public URL.',
        'no_region' => 'Assign the project to a region to attach custom domains.',
        'demo_region' => 'Custom domains are not available on a demo region.',
        'region_unavailable' => 'The project\'s region is not reachable for this change.',
        'txt_missing' => 'TXT record :name with the value ":value" was not found.',
        'not_pointing' => ':domain does not point to this project. Create one of these records: :targets.',
        'no_target' => 'The region has no external address to point the domain at.',
        'verify_failed' => 'The domain could not be verified.',
        'active' => 'The domain is verified and routed.',
        'apply_failed' => 'Routing the domain on the region failed: :message',
        'routed_elsewhere' => ':domain is already routed to another project on this region.',
        'region_changed' => 'The project moved to another region: update the DNS record and verify again.',
        'remove_failed' => 'Removing the domain from the region failed (:objects).',
        'tls_updated' => 'TLS setting updated.',
        'tls_apply_failed' => 'Applying the TLS setting on the region failed: :message',
        'key_mismatch' => 'The private key does not belong to the certificate.',
        'certificate_host_mismatch' => 'The certificate does not cover :domain.',
        'cert_manager_not_configured' => 'No cert-manager ClusterIssuer is configured for this region (region setting cert_manager_cluster_issuer).',
        'cert_manager_unavailable' => 'cert-manager (cert-manager.io/v1) or the ClusterIssuer ":issuer" is not present on the region cluster.',
        'certificate_pending' => 'cert-manager is issuing the certificate; HTTPS uses the region default certificate until it is ready. :reason',
        'tls_note_none' => 'Served over HTTP. HTTPS answers with the region\'s default certificate, which does not cover this domain.',
        'tls_note_custom' => 'HTTPS uses the uploaded certificate (valid until :date).',
        'tls_note_cert_manager' => 'HTTPS uses a certificate issued by cert-manager (ClusterIssuer :issuer).',
    ],

    // ── Code ──
    'code' => [
        'saved' => 'Code saved.',
        'files_saved' => 'Files saved.',
        'main_file_delete' => 'Main file (:file) cannot be deleted.',
        'handler_required' => 'Main file must contain a handler function.',
        'too_large' => 'The code is too large (:size bytes); at most :max bytes fit into a deployment.',
        'file_name_invalid' => 'Invalid file name ":name": use letters, digits, ".", "-" and "_" only (no folders).',
    ],

    // ── Registry ──
    'registry' => [
        'cannot_modify_demo' => 'Cannot modify demo region.',
        'token_expired' => 'Setup token not found or expired.',
        'cannot_retry' => 'Cannot retry: setup not in error state.',
    ],

    // ── Redis ──
    'redis' => [
        'installation_queued' => 'Redis installation queued.',
        'removal_queued' => 'Redis removal queued.',
    ],

    // ── Region Migration ──
    'migration' => [
        'in_progress' => 'Migration already in progress.',
        'started' => 'Migration started.',
        'not_dns_phase' => 'Migration is not in DNS check phase.',
        'completed' => 'Migration completed.',
        'cannot_rollback' => 'Migration cannot be rolled back in current state.',
        'rolled_back' => 'Migration rolled back.',
    ],

    // ── Tenant ──
    'tenant' => [
        'deleted' => 'Tenant deleted.',
        'deletion_started' => 'Tenant deletion started.',
        'deletion_running' => 'This tenant is already being deleted.',
        'deleting' => 'This tenant is being deleted; changes are not accepted.',
        'deletion_failed' => 'Tenant deletion incomplete: :failures',
        'access_denied' => 'Access denied.',
        'no_context' => 'No active tenant context.',
        'only_owners_admins' => 'Only owners and admins can impersonate tenants.',
        'unauthorized' => 'Unauthorized tenant access.',
        'admin_required' => 'Admin access required.',
        'not_member' => 'Not a member of this tenant.',
        'not_found' => 'Tenant not found.',
        'region_not_assignable' => 'Tenant-owned regions cannot be assigned.',
        'no_region_access' => 'Tenant has no access to this region.',
        'quota_exceeded' => 'Quota exceeded.',
        'already_impersonating' => 'Already impersonating. Stop current session first.',
        'impersonation_started' => 'Impersonation started.',
        'no_impersonation' => 'No active impersonation session.',
        'impersonation_ended' => 'Impersonation ended.',
        'cpu_quota_exceeded' => 'CPU quota exceeds remaining capacity (:remaining m available).',
        'memory_quota_exceeded' => 'Memory quota exceeds remaining capacity (:remaining MB available).',
    ],

    // ── Tenant User ──
    'tenant_user' => [
        'already_in' => 'User already in tenant.',
        'added' => 'User added.',
        'invitation_pending' => 'Invitation already pending.',
        'invitation_sent' => 'Invitation sent.',
        'cannot_change_owner' => 'Cannot change owner role.',
        'cannot_change_self' => 'Cannot change your own role.',
        'role_updated' => 'Role updated.',
        'cannot_remove_owner' => 'Cannot remove owner.',
        'cannot_remove_self' => 'Cannot remove yourself.',
        'removed' => 'User removed from tenant.',
        'invitation_cancelled' => 'Invitation cancelled.',
        'invitation_resent' => 'Invitation resent.',
        'cannot_change_admin' => 'Cannot change your own admin status.',
        'admin_updated' => 'Admin status updated.',
    ],

    // ── Invitation ──
    'invitation' => [
        'not_found' => 'Invitation not found or already used.',
        'expired' => 'Invitation has expired.',
        'invalid_password' => 'Invalid password.',
        'accepted' => 'Invitation accepted.',
    ],

    // ── OAuth ──
    'oauth' => [
        'invalid_code' => 'The sign-in link has expired or was already used. Sign in again.',
        'provider_deleted' => 'Provider deleted.',
        'unauthorized' => 'Unauthorized.',
        'account_unlinked' => 'Social account unlinked.',
        'provider_not_found' => 'Provider not found or disabled.',
        'no_email' => 'Provider did not return an email address.',
        'account_not_found' => 'Account not found. Contact your administrator.',
        'invalid_state' => 'Invalid or expired state parameter.',
    ],

    // ── SAML 2.0 ──
    'saml' => [
        'invalid_certificate' => 'Not a valid PEM X.509 certificate.',
        'invalid_private_key' => 'Not a valid unencrypted PEM private key.',
        'key_mismatch' => 'The private key does not belong to the SP certificate.',
        'sp_certificate_required' => 'Signing AuthnRequests requires an SP certificate.',
        'sp_key_required' => 'Signing AuthnRequests requires the SP private key.',
        'invalid_response' => 'The SAML response was rejected.',
        'unknown_request' => 'The SAML response does not answer a sign-in started here, or it was already used.',
    ],

    // ── Logs ──
    'logs' => [
        'demo_no_logs' => "Demo region — pods run virtually, no real logs available.\n",
        'no_kubeconfig' => 'Region kubeconfig not configured.',
        'pods_cleaned' => "Pods have been cleaned up — logs are no longer available for this deployment.\n",
        'no_pods' => "No pods found. The service may have scaled to zero or hasn't started yet.\n",
        'no_output' => "No log output yet.\n",
    ],

    // ── Shell ──
    'shell' => [
        'requires_active' => 'Shell access requires an active or running deployment.',
        'no_kubeconfig' => 'Region kubeconfig is not configured.',
        'demo_not_allowed' => 'Shell access is not available for demo regions.',
        'missing_token' => 'Missing token.',
        'session_expired' => 'Session not found or expired.',
        'invalid_config' => 'Invalid session config.',
        'wake_failed' => 'Failed to start wake process.',
        'wake_ended' => 'Wake process ended unexpectedly.',
        'pod_not_found' => 'Pod not found.',
        'start_failed' => 'Failed to start shell.',
        'idle_closed' => '[Session closed due to inactivity]',
        'log_missing_token' => 'Missing token.',
        'log_session_expired' => 'Session not found or expired.',
        'log_start_failed' => 'Failed to start log stream.',
    ],

    // ── Account ──
    'account' => [
        'profile_updated' => 'Profile updated.',
        'avatar_uploaded' => 'Avatar uploaded.',
        'avatar_removed' => 'Avatar removed.',
        'wrong_password' => 'Current password is incorrect.',
        'password_changed' => 'Password changed.',
        'disabled' => 'Account disabled.',
        'deleted' => 'Account deleted.',
        'account_disabled' => 'Your account has been disabled.',
        'invalid_2fa_code' => 'Invalid verification code.',
        '2fa_enabled' => 'Two-factor authentication enabled.',
        '2fa_disabled' => 'Two-factor authentication disabled.',
        'cannot_revoke_current' => 'Cannot revoke the current session.',
        'session_revoked' => 'Session revoked.',
        'all_sessions_revoked' => 'All other sessions revoked.',
        'preferences_updated' => 'Notification preferences updated.',
        '2fa_required' => 'Two-factor authentication code required.',
        'invalid_recovery_code' => 'Invalid recovery code.',
    ],

    // ── Contact ──
    'contact' => [
        'sent' => 'Your message has been sent successfully.',
    ],

    // ── Support tickets (notification titles) ──
    'tickets' => [
        'created_title' => 'New support ticket: :subject',
        'reply_title' => 'Reply on support ticket: :subject',
        'status_title' => 'Your support ticket status was updated: :subject',
        'staff_reply_title' => 'Your support ticket has a new reply: :subject',
    ],

    // ── Private connections (Skupper) ──
    'private_connection' => [
        'no_region' => 'The project needs a region with a kubeconfig.',
        'skupper_unavailable' => 'Skupper v2 (skupper.io/v2alpha1) is not installed on region :region.',
        'routing_key_in_use' => 'Another tenant already uses this routing key on the region.',
        'env_prefix_taken' => 'Another private connection of this project uses the same environment variable prefix.',
        'region_unreachable' => 'The region could not be reached: :error',
        'apply_failed' => 'The private connection could not be applied: :error',
        'remove_failed' => 'The private connection could not be removed from the region. Try again.',
        'deleted' => 'Private connection removed.',
    ],

    // ── Bindings (docs/bindings.md) ──
    'bindings' => [
        'region_unavailable' => 'Region :region has no usable cluster (kubeconfig) for bindings.',
        'region_missing' => 'The binding has no region.',
        'region_mismatch' => 'Binding :binding lives in another region than project :project.',
        'project_without_region' => 'The project needs a region before a binding can be created for it.',
        'service_not_ready' => 'The :service data service of region :region is not installed or not ready.',
        'service_not_installed' => 'The :service data service is not installed.',
        'service_busy' => 'The :service data service is being installed or removed. Try again when it is done.',
        'service_in_use' => 'The :service data service still has :count binding(s). Delete them first.',
        'service_install_queued' => 'Installing the :service data service.',
        'service_uninstall_queued' => 'Removing the :service data service.',
        'service_timeout' => ':service did not become ready in time.',
        'service_pods_not_ready' => 'Not every pod of the service is ready.',
        'quota_exceeded' => 'Binding limit reached (:key: :used of :limit used, :requested requested).',
        'not_active' => 'Binding :name is being deleted.',
        'not_ready' => 'Binding :name is :status, not ready.',
        'not_deleting' => 'Binding :name is not scheduled for deletion.',
        'already_attached' => 'Binding :name is already attached to this project.',
        'env_conflict' => 'These environment variables are already set by another binding of the project: :names. Use another prefix.',
        'confirm_name' => 'Type the binding name to confirm the deletion.',
        'deletion_scheduled' => 'Binding detached and scheduled for deletion. Its data is deleted on :date unless you restore it.',
        'restored' => 'Binding restored. Attach it to projects again to use it.',
        'detached' => 'Binding detached from the project.',
        'rotation_queued' => 'Rotating credentials. Attached functions restart with the new ones.',
        'rotation_failed' => 'Credential rotation failed: :error',
        'purge_failed' => 'Deleting the binding data failed: :error',
        'over_quota' => 'Over its size limit: writes are refused until data is deleted or the limit is raised.',
        'storage_shrink' => 'The storage of a dedicated Postgres cluster can only grow.',
        'values_external_only' => 'Only external bindings have values.',
        'invalid_env_key' => ':key is not a valid environment variable name (A-Z, 0-9, _; starts with a letter).',
        'dedicated_postgres_only' => 'Only Postgres bindings have a dedicated plan.',
        'kv_region_redis_missing' => 'The region has no ready Redis (Region → Redis).',
        'kv_region_password_required' => 'KV bindings on the region Redis need a Redis password: without one every pod of the namespace has full access.',
        'kv_unreachable' => 'Redis could not be reached from the region: :error',
        'kv_no_free_database' => 'The region Redis has no free logical database (1–15) left.',
        'kv_operation_failed' => 'Redis operation :operation failed: :error',
        'object_admin_failed' => 'Object store admin API call failed: :error',
        'object_operation_failed' => 'Object store operation failed: :error',
        'object_bucket_missing' => 'Bucket :bucket does not exist on the object store.',
        'object_unreachable' => 'The object store could not be reached.',
        'object_used_by_backups' => 'Postgres backups use the object store. Turn them off first.',
        'backups_need_object_store' => 'Postgres backups need the region object store (Region → Data services).',
        'cnpg_install_failed' => 'CloudNativePG could not be installed: :error',
        'postgres_cluster_failed' => 'Postgres cluster :cluster could not be created.',
        'postgres_cluster_missing' => 'Postgres cluster :cluster does not exist.',
        'postgres_sql_failed' => 'Postgres operation failed: :error',
    ],

    // ── Billing ──
    'billing' => [
        'invalid_period' => 'Give the period as YYYY-MM.',
        'range_too_long' => 'The range may span at most :days days.',
        'purchase_ref_taken' => 'This external_ref is already recorded for another tenant or kind of purchase.',
        'credit_amount_required' => 'A credit needs an amount above 0 and a currency.',
        'addon_limits_required' => 'An add-on needs at least one limit above 0.',
        'ends_at_past' => 'ends_at must lie in the future.',
        'webhook_not_configured' => 'No billing webhook is configured (Settings → Plans & Billing or BILLING_WEBHOOK_URL).',
    ],

    // ── Rate limiting ──
    'rate_limit' => [
        'exceeded' => 'Too many requests. Try again in :seconds seconds.',
    ],

    // ── Runtimes (runtime plugins) ──
    'runtimes' => [
        'unknown' => "Runtime ':id' is not available.",
        'disabled' => 'The :name runtime is disabled by the platform administrator.',
        'not_in_region' => 'The :name runtime is not offered in this region.',
        'trigger_unsupported' => 'The :name runtime does not support the :trigger trigger.',
        'sandbox_unsupported' => 'The :name runtime does not run in the :sandbox sandbox this region uses for :type projects.',
        'id_taken' => "A runtime with the id ':id' already exists.",
        'id_immutable' => "The runtime id can not change (':id').",
        'runtime_class_platform_only' => 'runtimeClass is available to platform runtimes only.',
        'invalid_manifest' => 'The runtime manifest is not valid.',
        'file_name_invalid' => 'Invalid file name ":name": use letters, digits, ".", "-" and "_" only (no folders).',
        'files_too_large' => 'The uploaded files are too large: at most :kb KB in total.',
        'builtin_readonly' => 'Built-in runtimes come with the release: only their availability (enabled, regions) can change.',
        'builtin_delete' => 'Built-in runtimes can not be deleted; disable them instead.',
        'in_use' => ':count project(s) use this runtime; move or delete them first.',
        'no_source_versions' => 'This runtime has no version built from a dockerfile or git source.',
        'test_deploy_description' => 'Test deploy of the :name runtime',
        'custom_not_allowed' => 'Custom runtimes are not enabled on this platform.',
    ],

    // ── Secret environment variables ──
    'secret' => [
        'value_too_large' => 'A secret value may be at most :max KiB.',
        'too_many' => 'A project may have at most :max secret variables.',
        'name_is_plain' => '":key" is already a plain environment variable of this project; remove it there first.',
        'plain_is_secret' => '":key" is a secret variable of this project; change it under Secret variables.',
    ],

    // ── Audit log ──
    'audit' => [
        'settings_forbidden' => 'Only a platform administrator can change the audit log retention.',
    ],

    // ── Misc ──
    'all_fields_required' => 'All fields required.',
    'internal_error' => 'Internal error.',
];
