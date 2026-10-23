<?php

namespace Tests\Feature\Secrets;

use App\Models\AuditEvent;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\RegionSecretSetting;
use App\Models\SecretStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * The organization's secret stores (docs/secret-stores.md): settings per provider and nothing
 * else — no credential is ever taken —, the trust a store needs for a region (its cluster's
 * issuer, keys and the service account), the check in a region, and the permissions.
 */
class SecretStoreApiTest extends TestCase
{
    use ActsWithRoles, FakesRegionSecrets, RefreshDatabase;

    private User $owner;

    private User $member;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->userWithRole('owner');
        $this->member = $this->userWithRole('developer');
        $this->region = $this->regionWithCluster('fn');
    }

    private function vault(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'team-vault',
            'provider' => 'vault',
            'auth' => 'kubernetes',
            'settings' => ['server' => 'https://vault.example.test:8200', 'role' => 'runr-fn', 'mount' => 'secret'],
            'refresh_interval' => 900,
        ], $overrides);
    }

    public function test_a_store_is_registered_with_settings_only(): void
    {
        $response = $this->signedIn($this->owner)->postJson('/api/secret-stores', $this->vault())
            ->assertCreated()
            ->assertJsonPath('data.name', 'team-vault')
            ->assertJsonPath('data.provider', 'vault')
            ->assertJsonPath('data.auth', 'kubernetes')
            ->assertJsonPath('data.settings.role', 'runr-fn')
            ->assertJsonPath('data.refresh_interval', 900)
            ->assertJsonPath('data.references', 0);

        $store = SecretStore::sole();
        $response->assertJsonPath('data.kubernetes_name', 'runr-ss-'.strtolower($store->id));
        $this->assertTrue(AuditEvent::where('action', 'secret_store.create')->where('result', 'success')->exists());

        $this->signedIn($this->member)->getJson('/api/secret-stores')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'team-vault')
            ->assertJsonFragment(['provider' => 'aws', 'auth' => ['workload_identity']]);
    }

    public function test_no_credential_is_taken_for_a_store(): void
    {
        foreach (['token', 'secret_id', 'password', 'access_key_id', 'secret_access_key', 'client_secret', 'credentials_json'] as $credential) {
            $this->signedIn($this->owner)
                ->postJson('/api/secret-stores', $this->vault(['name' => 'v-'.str_replace('_', '-', $credential), 'settings' => [$credential => 'do-not-store']]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors("settings.{$credential}");
        }

        // A CA bundle is public; a private key in it is refused
        $this->signedIn($this->owner)
            ->postJson('/api/secret-stores', $this->vault(['settings' => ['ca_bundle' => "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----"]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings.ca_bundle');

        $this->assertSame(0, SecretStore::count());
    }

    public function test_each_provider_takes_its_own_settings_and_auth(): void
    {
        $this->signedIn($this->owner)->postJson('/api/secret-stores', [
            'name' => 'aws', 'provider' => 'aws',
            'settings' => ['region' => 'eu-central-1', 'role_arn' => 'arn:aws:iam::123456789012:role/runr'],
        ])->assertCreated()->assertJsonPath('data.auth', 'workload_identity')->assertJsonPath('data.audience', 'sts.amazonaws.com');

        $this->signedIn($this->owner)->postJson('/api/secret-stores', [
            'name' => 'aws-bad', 'provider' => 'aws', 'settings' => ['region' => 'eu-central-1', 'role_arn' => 'not-an-arn'],
        ])->assertUnprocessable()->assertJsonValidationErrors('settings.role_arn');

        $this->signedIn($this->owner)->postJson('/api/secret-stores', [
            'name' => 'azure', 'provider' => 'azure', 'auth' => 'kubernetes',
            'settings' => ['vault_url' => 'https://acme.vault.azure.net', 'tenant_id' => 't', 'client_id' => 'c'],
        ])->assertUnprocessable()->assertJsonValidationErrors('auth');

        $this->signedIn($this->owner)->postJson('/api/secret-stores', [
            'name' => 'gcp', 'provider' => 'gcp', 'settings' => ['project_id' => 'acme'],
        ])->assertUnprocessable()->assertJsonValidationErrors('settings.audience');

        $this->signedIn($this->owner)->postJson('/api/secret-stores', $this->vault(['name' => 'Bad Name']))
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_a_store_is_updated_but_keeps_its_provider(): void
    {
        $this->signedIn($this->owner)->postJson('/api/secret-stores', $this->vault())->assertCreated();
        $store = SecretStore::sole();

        $this->signedIn($this->owner)->putJson("/api/secret-stores/{$store->id}", ['auth' => 'jwt', 'settings' => ['role' => 'runr-jwt', 'audience' => 'vault-fn']])
            ->assertOk()
            ->assertJsonPath('data.auth', 'jwt')
            ->assertJsonPath('data.settings.role', 'runr-jwt')
            ->assertJsonPath('data.settings.server', 'https://vault.example.test:8200')
            ->assertJsonPath('data.audience', 'vault-fn');

        $this->signedIn($this->owner)->putJson("/api/secret-stores/{$store->id}", ['provider' => 'aws'])
            ->assertUnprocessable()->assertJsonValidationErrors('provider');
    }

    public function test_a_store_in_use_cannot_be_deleted(): void
    {
        $this->signedIn($this->owner)->postJson('/api/secret-stores', $this->vault())->assertCreated();
        $store = SecretStore::sole();
        $project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        ProjectSecret::create(['project_id' => $project->id, 'name' => 'DB_URL', 'source' => 'external', 'secret_store_id' => $store->id, 'remote_path' => 'apps/orders']);

        $this->signedIn($this->owner)->deleteJson("/api/secret-stores/{$store->id}")
            ->assertStatus(409)
            ->assertJsonPath('projects', ['orders']);

        ProjectSecret::query()->delete();
        $this->signedIn($this->owner)->deleteJson("/api/secret-stores/{$store->id}")->assertNoContent();
        $this->assertSame(0, SecretStore::count());
    }

    public function test_developers_see_stores_but_do_not_manage_them(): void
    {
        $this->signedIn($this->owner)->postJson('/api/secret-stores', $this->vault())->assertCreated();
        $store = SecretStore::sole();
        $this->app['auth']->forgetGuards();

        $this->signedIn($this->member)->getJson("/api/secret-stores/{$store->id}")->assertOk();
        $this->signedIn($this->member)->postJson('/api/secret-stores', $this->vault(['name' => 'other']))->assertForbidden();
        $this->signedIn($this->member)->putJson("/api/secret-stores/{$store->id}", ['refresh_interval' => 120])->assertForbidden();
        $this->signedIn($this->member)->deleteJson("/api/secret-stores/{$store->id}")->assertForbidden();

        $viewer = $this->userWithRole('viewer');
        $this->signedIn($viewer)->getJson('/api/secret-stores')->assertForbidden();
    }

    public function test_the_trust_names_the_clusters_issuer_keys_and_service_account(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $b64url = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        $jwks = ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'k1', 'n' => $b64url($rsa['n']), 'e' => $b64url($rsa['e'])]]];

        Http::fake(function (Request $request) use ($jwks) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/.well-known/openid-configuration' => Http::response(['issuer' => 'https://oidc.fn.example.com', 'jwks_uri' => 'https://oidc.fn.example.com/openid/v1/jwks']),
                '/openid/v1/jwks' => Http::response($jwks),
                default => Http::response([], 404),
            };
        });

        $this->signedIn($this->owner)->postJson('/api/secret-stores', $this->vault())->assertCreated();
        $store = SecretStore::sole();

        $data = $this->signedIn($this->member)->getJson("/api/secret-stores/{$store->id}/trust?region_id={$this->region->id}")
            ->assertOk()
            ->assertJsonPath('data.issuer', 'https://oidc.fn.example.com')
            ->assertJsonPath('data.publicly_reachable', true)
            ->assertJsonPath('data.namespace', 'fn')
            ->assertJsonPath('data.service_account', $store->kubernetesName())
            ->assertJsonPath('data.subject', 'system:serviceaccount:fn:'.$store->kubernetesName())
            ->assertJsonPath('data.jwks.keys.0.kid', 'k1')
            ->assertJsonPath('data.eso_status', 'not_installed')
            ->json('data');

        $this->assertCount(1, $data['pem_keys']);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $data['pem_keys'][0]);
    }

    public function test_an_issuer_inside_the_cluster_is_flagged_for_the_clouds(): void
    {
        Http::fake(fn (Request $request) => str_ends_with($request->url(), 'openid-configuration')
            ? Http::response(['issuer' => 'https://kubernetes.default.svc.cluster.local'])
            : Http::response(['keys' => []]));
        $this->signedIn($this->owner)->postJson('/api/secret-stores', $this->vault())->assertCreated();

        $this->signedIn($this->owner)->getJson('/api/secret-stores/'.SecretStore::sole()->id.'/trust?region_id='.$this->region->id)
            ->assertOk()
            ->assertJsonPath('data.publicly_reachable', false)
            ->assertJsonPath('data.pem_keys', []);
    }

    public function test_a_store_is_verified_in_a_region_with_the_operator(): void
    {
        $this->signedIn($this->owner)->postJson('/api/secret-stores', $this->vault())->assertCreated();
        $store = SecretStore::sole();

        $this->signedIn($this->owner)->postJson("/api/secret-stores/{$store->id}/verify", ['region_id' => $this->region->id])
            ->assertUnprocessable();

        RegionSecretSetting::forRegion($this->region)->fill(['eso_status' => RegionSecretSetting::ESO_READY])->save();
        $applied = [];
        Http::fake(function (Request $request) use (&$applied, $store) {
            if ($request->method() === 'PATCH') {
                $applied[] = parse_url($request->url(), PHP_URL_PATH);

                return Http::response(['kind' => 'Status'], 200);
            }

            return Http::response(['metadata' => ['name' => $store->kubernetesName()], 'status' => ['conditions' => [
                ['type' => 'Ready', 'status' => 'False', 'reason' => 'InvalidProviderConfig', 'message' => 'unable to log in: permission denied'],
            ]]]);
        });

        $this->signedIn($this->owner)->postJson("/api/secret-stores/{$store->id}/verify", ['region_id' => $this->region->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.message', 'unable to log in: permission denied');

        $this->assertContains('/api/v1/namespaces/fn/serviceaccounts/'.$store->kubernetesName(), $applied);
        $this->assertContains('/apis/external-secrets.io/v1/namespaces/fn/secretstores/'.$store->kubernetesName(), $applied);
        $this->assertSame('failed', $store->fresh()->statusIn($this->region->id)['status']);
        $this->assertTrue(AuditEvent::where('action', 'secret_store.verify')->exists());
    }
}
