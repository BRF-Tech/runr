<?php

namespace App\Support\Secrets;

/**
 * Every database column that holds a secret, per schema (docs/security.md, "Encryption at rest").
 *
 *   json  — JSON value encrypted as a whole (cast App\Casts\Encrypted:array); the column type is
 *           text so the payload fits on PostgreSQL / MySQL (it was json/jsonb before)
 *   text  — string value (App\Casts\Encrypted or Laravel's `encrypted` cast)
 *   keyed — already written encrypted by the application (Laravel's encrypt()/`encrypted`
 *           cast); never plaintext, only re-encrypted on key rotation
 *
 * SecretColumnEncryptor walks this list for the data migrations and `php artisan secrets:encrypt`.
 */
final class SecretColumns
{
    public const JSON = 'json';

    public const TEXT = 'text';

    public const KEYED = 'keyed';

    /** Columns of tables every schema has (central / single-tenant and each tenant schema). */
    private const SHARED = [
        'xaas_configs' => ['env_vars' => self::JSON],
        'faas_configs' => ['env_vars' => self::JSON],
        'container_configs' => ['env_vars' => self::JSON],
        'aaas_configs' => ['env_vars' => self::JSON],
        'agent_flow_configs' => ['env_vars' => self::JSON],
        'maas_configs' => ['env_vars' => self::JSON],
        'versions' => ['config_snapshot' => self::JSON],
        'knative_regions' => [
            'kubeconfig' => self::TEXT,
            'migration_old_kubeconfig' => self::TEXT,
            'redis_password' => self::TEXT,
            'registry_password' => self::TEXT,
            'registry_storage_config' => self::JSON,
        ],
        'project_secrets' => ['value' => self::TEXT],
        'auth_providers' => [
            'client_id' => self::KEYED,
            'client_secret' => self::KEYED,
            'saml_sp_private_key' => self::KEYED,
        ],
        'social_accounts' => [
            'access_token' => self::KEYED,
            'refresh_token' => self::KEYED,
        ],
        'users' => [
            'two_factor_secret' => self::KEYED,
            'two_factor_recovery_codes' => self::KEYED,
        ],
        // Hugging Face token of the organization; env vars of model services (docs/huggingface.md)
        'ai_hub_credentials' => ['token' => self::TEXT],
        'ai_model_services' => ['env_vars' => self::JSON],
        // Bindings (docs/bindings.md): credentials and their rotation predecessor, data service
        // admin credentials (App\Casts\Encrypted:array)
        'bindings' => [
            'credentials' => self::JSON,
            'previous_credentials' => self::JSON,
            // the data link of a binding of a global project (docs/global-projects.md, "Data")
            'replication' => self::JSON,
        ],
        'binding_region_services' => ['secrets' => self::JSON],
        // Durable workflows: HMAC signing keys (Laravel's `encrypted` cast)
        'workflow_apps' => [
            'signing_key' => self::KEYED,
            'signing_key_previous' => self::KEYED,
        ],
        // Observability (docs/observability.md): alert channel credentials (webhook URLs, bot
        // tokens, HMAC secrets), uptime check headers, captured failed requests
        'alert_channels' => ['config' => self::JSON],
        'uptime_checks' => ['headers' => self::JSON],
        'replay_captures' => [
            'headers' => self::JSON,
            'response_headers' => self::JSON,
            'last_replay' => self::JSON,
            'body' => self::TEXT,
            'response_body' => self::TEXT,
        ],
        // Git deploy: env overrides of preview environments (docs/git-deploy.md)
        'project_git_settings' => ['preview_env' => self::JSON],
        // Environments (docs/environments.md): config overrides (env var values), the effective
        // config a deployment ran with (a rollback restores it). An environment's secret env vars
        // are not here: their values live in its region (environment_secrets holds metadata only)
        'project_environments' => ['overrides' => self::JSON],
        'deployments' => ['config_snapshot' => self::JSON],
        'queued_deploys' => ['config_snapshot' => self::JSON],
    ];

    /** Columns of central-only tables. */
    private const CENTRAL_ONLY = [
        'log_retention_configs' => ['storage_config' => self::JSON],
        'sign_in_handoffs' => ['token' => self::TEXT],
        // Headers (credentials) of a region's external OTLP export
        'region_tracing_configs' => ['export_headers' => self::JSON],
        // Sandbox API: the env of a sandbox (central table of every organization's sandboxes)
        'sandboxes' => ['env' => self::JSON],
        // White-label platform secrets: payment gateway keys, CAPTCHA secret (`encrypted` cast)
        'secure_settings' => ['value' => self::KEYED],
        // Region cosign key pair: the API's copy of the encrypted private key and its password
        // (docs/supply-chain.md, "Signing"); the working copy is a Kubernetes Secret in the region
        'region_supply_chain_settings' => [
            'signing_private_key' => self::TEXT,
            'signing_key_password' => self::TEXT,
        ],
        // Git deploy (docs/git-deploy.md): GitHub App key and secrets, GitLab OAuth application
        // secret; per repository the access / refresh token and the webhook secret
        'git_providers' => [
            'client_secret' => self::TEXT,
            'private_key' => self::TEXT,
            'webhook_secret' => self::TEXT,
        ],
        'git_repo_links' => [
            'access_token' => self::TEXT,
            'refresh_token' => self::TEXT,
            'webhook_secret' => self::TEXT,
        ],
        // Hosted MCP servers (docs/mcp.md): the gateway key and its rotation predecessor
        // (App\Casts\Encrypted; central schema in both editions, rows carry tenant_id)
        'mcp_servers' => [
            'signing_key' => self::TEXT,
            'signing_key_previous' => self::TEXT,
        ],
        // Global DNS (docs/global-projects.md): the API key of a region's PowerDNS server
        'region_global_dns' => ['api_key' => self::TEXT],
    ];

    /** Primary key of tables that do not use `id`. */
    private const KEY_COLUMNS = [
        'secure_settings' => 'key',
        'region_global_dns' => 'knative_region_id',
    ];

    public static function keyColumn(string $table): string
    {
        return self::KEY_COLUMNS[$table] ?? 'id';
    }

    /** @return array<string, array<string, string>> table => [column => kind] */
    public static function central(): array
    {
        return self::SHARED + self::CENTRAL_ONLY;
    }

    /** @return array<string, array<string, string>> table => [column => kind] */
    public static function tenant(): array
    {
        return self::SHARED;
    }

    /** @return list<string> secret column names of $table (both schemas) */
    public static function forTable(string $table): array
    {
        return array_keys(self::central()[$table] ?? []);
    }
}
