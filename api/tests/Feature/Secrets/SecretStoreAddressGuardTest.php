<?php

namespace Tests\Feature\Secrets;

use App\Models\SecretStore;
use App\Services\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * A secret store of an organization of the multi-tenant edition names the address the region's
 * External Secrets Operator connects to (a Vault / OpenBao server, an Azure Key Vault URL): it
 * must be public (the outbound guard's ranges), checked when saved, verified and deployed, and a
 * failure is shown without what the address answered. The platform's own stores are not checked.
 */
class SecretStoreAddressGuardTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private const MIGRATIONS = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0078_project_secrets_pass_through.php',
    ];

    /** @var array<string, list<string>> */
    public array $dns = ['vault.acme.example' => ['93.184.216.34'], 'vault.internal.example' => ['10.0.0.7'], 'kv.vault.azure.net' => ['93.184.216.35']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();

        $test = $this;
        $this->app->instance(DnsResolver::class, new class($test) extends DnsResolver
        {
            public function __construct(private readonly SecretStoreAddressGuardTest $test) {}

            public function addresses(string $name): array
            {
                return $this->test->dns[$name] ?? [];
            }
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function vault(string $server): SecretStore
    {
        return new SecretStore(['name' => 'vault', 'provider' => 'vault', 'auth' => 'kubernetes', 'settings' => ['server' => $server, 'role' => 'runr']]);
    }

    public function test_an_organizations_store_names_a_public_address(): void
    {
        $tenant = $this->createTenantWithSchema('acme', self::MIGRATIONS);

        $tenant->run(function () {
            foreach (['https://vault.internal.example:8200', 'https://169.254.169.254', 'https://[::ffff:10.0.0.7]:8200',
                'https://[64:ff9b::a9fe:a9fe]', 'http://vault.vault.svc.cluster.local:8200'] as $server) {
                $this->assertSame(['server', __('observability.url.private')], $this->vault($server)->addressProblem(), $server);
            }
            $this->assertNull($this->vault('https://vault.acme.example:8200')->addressProblem());
            $this->assertNull($this->vault('https://vault.not-yet.example')->addressProblem(), 'a name that does not resolve yet');

            $azure = new SecretStore(['provider' => 'azure', 'settings' => ['vault_url' => 'http://kv.vault.azure.net']]);
            $this->assertSame(['vault_url', __('observability.url.https_required')], $azure->addressProblem());

            config(['secrets.external.allow_private_targets' => true]);
            $this->assertNull($this->vault('https://vault.internal.example:8200')->addressProblem(), 'the operator allows private stores');
        });

        // The platform's own stores (central context) are not checked
        $this->assertNull($this->vault('https://vault.internal.example:8200')->addressProblem());
    }

    public function test_an_organization_sees_a_generic_failure(): void
    {
        $tenant = $this->createTenantWithSchema('acme', self::MIGRATIONS);

        $tenant->run(function () {
            $store = $this->vault('https://vault.acme.example:8200');
            $store->save();

            $store->recordStatus('r1', 'failed', 'Get "https://10.0.0.7:8200/v1/auth/kubernetes/login": dial tcp 10.0.0.7:8200: connection refused');
            $this->assertSame(__('api.secret_store.not_ready'), $store->fresh()->statusIn('r1')['message']);

            $store->recordStatus('r1', 'ready', 'store validated');
            $this->assertSame('store validated', $store->fresh()->statusIn('r1')['message']);
        });
    }
}
