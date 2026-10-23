<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A secret store of the organization (docs/secret-stores.md): HashiCorp Vault / OpenBao, AWS
 * Secrets Manager, GCP Secret Manager or Azure Key Vault. A project secret can be a reference to
 * a value in it (ProjectSecret source "external"); External Secrets Operator reads the value in
 * the project's region and puts it into the Kubernetes Secret <slug>-ext-env.
 *
 * The store authenticates with the region's identity, never with a credential RunR keeps: a
 * service account of the region cluster (KUBERNETES_NAME in the project namespace) that the
 * store trusts — Kubernetes or JWT auth of Vault / OpenBao, workload identity federation of the
 * clouds. `settings` holds addresses, mounts and roles only (SETTINGS lists what each provider
 * takes; anything else is refused).
 *
 * @property string $id
 * @property string $name
 * @property string $provider vault | aws | gcp | azure
 * @property string $auth kubernetes | jwt | workload_identity
 * @property array $settings
 * @property int|null $refresh_interval seconds
 * @property array|null $region_status region id => {status, message, checked_at}
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SecretStore extends Model
{
    use HasUlids;

    /** Provider => the ways it authenticates (the first is the default). */
    public const PROVIDERS = [
        'vault' => ['kubernetes', 'jwt'],
        'aws' => ['workload_identity'],
        'gcp' => ['workload_identity'],
        'azure' => ['workload_identity'],
    ];

    /**
     * The settings each provider (and auth) takes, name => required. No credential among them:
     * the store trusts the region's service account.
     */
    public const SETTINGS = [
        'vault' => [
            'server' => true,           // https://vault.example.com:8200
            'mount' => false,           // KV mount, default "secret"
            'kv_version' => false,      // v1 | v2 (default)
            'vault_namespace' => false, // Vault Enterprise / OpenBao namespace
            'ca_bundle' => false,       // PEM of the server's CA (public)
            'auth_mount' => false,      // default "kubernetes" / "jwt"
            'role' => true,
            'audience' => false,        // jwt: the token's audience, default "vault"
        ],
        'aws' => [
            'region' => true,           // eu-central-1
            'role_arn' => true,         // arn:aws:iam::123456789012:role/runr-secrets
            'audience' => false,        // default sts.amazonaws.com
        ],
        'gcp' => [
            'project_id' => true,
            'audience' => true,         // //iam.googleapis.com/projects/<n>/locations/global/workloadIdentityPools/<pool>/providers/<provider>
            'service_account_email' => false, // impersonated Google service account
        ],
        'azure' => [
            'vault_url' => true,        // https://<name>.vault.azure.net
            'tenant_id' => true,
            'client_id' => true,        // the app registration / managed identity with the federated credential
        ],
    ];

    /** Default audiences of the service account tokens a store logs in with. */
    public const DEFAULT_AUDIENCES = [
        'vault' => 'vault',
        'aws' => 'sts.amazonaws.com',
        'azure' => 'api://AzureADTokenExchange',
    ];

    protected $fillable = ['name', 'provider', 'auth', 'settings', 'refresh_interval', 'region_status', 'created_by'];

    protected $casts = [
        'settings' => 'array',
        'region_status' => 'array',
        'refresh_interval' => 'integer',
    ];

    /** @return HasMany<ProjectSecret, $this> */
    public function secrets(): HasMany
    {
        return $this->hasMany(ProjectSecret::class, 'secret_store_id');
    }

    /** Name of the SecretStore and of the service account it logs in with, in the project namespace. */
    public function kubernetesName(): string
    {
        return 'runr-ss-'.strtolower((string) $this->id);
    }

    /** The audience of the service account tokens the store logs in with. */
    public function audience(): ?string
    {
        $settings = (array) $this->settings;

        return match ($this->provider) {
            'gcp' => (string) ($settings['audience'] ?? ''),
            'vault' => $this->auth === 'jwt' ? (string) ($settings['audience'] ?? self::DEFAULT_AUDIENCES['vault']) : null,
            default => (string) ($settings['audience'] ?? self::DEFAULT_AUDIENCES[$this->provider] ?? ''),
        };
    }

    public function setting(string $name, mixed $default = null): mixed
    {
        $value = ((array) $this->settings)[$name] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    /** The refresh interval ExternalSecrets of this store use (seconds). */
    public function refreshSeconds(): int
    {
        return max(60, (int) ($this->refresh_interval ?: config('secrets.external.refresh_interval', 3600)));
    }

    /** The store's state in a region (verify / status sync). */
    public function statusIn(string $regionId): ?array
    {
        $status = ((array) ($this->region_status ?? []))[$regionId] ?? null;

        return is_array($status) ? $status : null;
    }

    public function recordStatus(string $regionId, string $status, ?string $message): void
    {
        $all = (array) ($this->region_status ?? []);
        $all[$regionId] = ['status' => $status, 'message' => $message !== null ? mb_substr($message, 0, 1000) : null, 'checked_at' => now()->toISOString()];
        $this->forceFill(['region_status' => $all])->save();
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider,
            'auth' => $this->auth,
            'settings' => (array) $this->settings,
            'refresh_interval' => $this->refreshSeconds(),
            'kubernetes_name' => $this->kubernetesName(),
            'audience' => $this->audience(),
            'regions' => (object) ((array) ($this->region_status ?? [])),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
