<?php

namespace Tests\Feature\Secrets;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\SecretStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * What External Secrets Operator reports in the region, onto the references and stores
 * (`php artisan secrets:sync-status`, POST …/secrets/refresh): synced with the last sync time,
 * failed with the operator's message, pending while the reference is not applied yet.
 */
class ExternalSecretStatusSyncTest extends TestCase
{
    use ActsWithRoles, FakesRegionSecrets, RefreshDatabase;

    private KnativeRegion $region;

    private SecretStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = $this->regionWithCluster('fn');
        $this->store = SecretStore::create(['name' => 'vault', 'provider' => 'vault', 'auth' => 'kubernetes', 'settings' => ['server' => 'https://vault.test', 'role' => 'r']]);
    }

    private function projectWithReference(string $name, string $variable): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'faas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        ProjectSecret::create([
            'project_id' => $project->id, 'name' => $variable, 'source' => 'external',
            'secret_store_id' => $this->store->id, 'remote_path' => "apps/{$name}", 'sync_status' => 'pending',
        ]);

        return $project;
    }

    /** @param list<array> $externalSecrets */
    private function fakeRegion(array $externalSecrets, array $stores = []): void
    {
        Http::fake(function (Request $request) use ($externalSecrets, $stores) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/externalsecrets') => Http::response(['items' => $externalSecrets]),
                str_ends_with($path, '/secretstores') => Http::response(['items' => $stores]),
                default => Http::response(['kind' => 'Status'], 200),
            };
        });
    }

    private static function externalSecret(string $slug, array $keys, string $status, ?string $message = null, ?string $refreshed = null): array
    {
        return [
            'metadata' => ['name' => "{$slug}-ext-env", 'labels' => ['runr/secret-source' => 'external']],
            'spec' => ['data' => array_map(fn ($k) => ['secretKey' => $k], $keys)],
            'status' => array_filter([
                'conditions' => [['type' => 'Ready', 'status' => $status, 'message' => $message]],
                'refreshTime' => $refreshed,
            ]),
        ];
    }

    public function test_the_status_of_each_reference_is_read_from_its_external_secret(): void
    {
        $orders = $this->projectWithReference('orders', 'DB_URL');
        $billing = $this->projectWithReference('billing', 'API_KEY');
        $fresh = $this->projectWithReference('fresh', 'TOKEN');

        $this->fakeRegion([
            self::externalSecret('orders', ['DB_URL'], 'True', 'secret synced', '2026-10-21T10:00:00Z'),
            self::externalSecret('billing', ['API_KEY'], 'False', 'could not get secret data from provider: permission denied'),
        ], [[
            'metadata' => ['name' => $this->store->kubernetesName(), 'labels' => ['runr/secret-source' => 'store']],
            'status' => ['conditions' => [['type' => 'Ready', 'status' => 'True', 'message' => 'store validated']]],
        ]]);

        $this->artisan('secrets:sync-status')->assertSuccessful();

        $synced = ProjectSecret::where('project_id', $orders->id)->sole();
        $this->assertSame('synced', $synced->sync_status);
        $this->assertSame('2026-10-21 10:00:00', $synced->synced_at?->utc()->format('Y-m-d H:i:s'));

        $failed = ProjectSecret::where('project_id', $billing->id)->sole();
        $this->assertSame('failed', $failed->sync_status);
        $this->assertStringContainsString('permission denied', (string) $failed->sync_message);

        // Not deployed since the reference was set: nothing in the region yet
        $pending = ProjectSecret::where('project_id', $fresh->id)->sole();
        $this->assertSame('pending', $pending->sync_status);

        $this->assertSame('ready', $this->store->fresh()->statusIn($this->region->id)['status']);
    }

    public function test_a_status_write_is_not_a_change_of_the_variable(): void
    {
        $orders = $this->projectWithReference('orders', 'DB_URL');
        $before = ProjectSecret::where('project_id', $orders->id)->sole()->updated_at;
        $this->travel(5)->minutes();
        $this->fakeRegion([self::externalSecret('orders', ['DB_URL'], 'True')]);

        $this->artisan('secrets:sync-status')->assertSuccessful();

        $row = ProjectSecret::where('project_id', $orders->id)->sole();
        $this->assertSame('synced', $row->sync_status);
        $this->assertEquals($before, $row->updated_at);
    }

    public function test_sync_now_asks_the_operator_and_answers_with_the_list(): void
    {
        $orders = $this->projectWithReference('orders', 'DB_URL');
        $patched = [];
        Http::fake(function (Request $request) use (&$patched) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'PATCH') {
                $patched[] = [$path, json_decode($request->body(), true)];

                return Http::response(['kind' => 'Status'], 200);
            }

            return str_ends_with($path, '/externalsecrets')
                ? Http::response(['items' => [self::externalSecret('orders', ['DB_URL'], 'True', null, '2026-10-21T11:00:00Z')]])
                : Http::response(['items' => []]);
        });

        $this->signedIn($this->userWithRole('developer'))
            ->postJson("/api/projects/{$orders->id}/secrets/refresh")
            ->assertOk()
            ->assertJsonPath('data.0.sync.status', 'synced');

        $this->assertSame('/apis/external-secrets.io/v1/namespaces/fn/externalsecrets/orders-ext-env', $patched[0][0]);
        $this->assertArrayHasKey('force-sync', $patched[0][1]['metadata']['annotations']);
    }
}
