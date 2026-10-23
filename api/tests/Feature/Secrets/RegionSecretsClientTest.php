<?php

namespace Tests\Feature\Secrets;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Secrets\RegionSecrets;
use App\Services\Secrets\SecretBackendException;
use App\Services\Secrets\SecretScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * The region client of the values Secret (RegionSecrets) against a faked API server: keys are set
 * and removed with a JSON merge patch, the Secret is created with the project's labels on the first
 * write, and a failure carries the status — never a value.
 */
class RegionSecretsClientTest extends TestCase
{
    use FakesRegionSecrets, RefreshDatabase;

    private SecretScope $scope;

    protected function setUp(): void
    {
        parent::setUp();

        $region = $this->regionWithCluster('fn');
        $project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        $this->scope = SecretScope::forProject($project->fresh());
    }

    public function test_keys_are_merge_patched_and_the_secret_created_on_the_first_write(): void
    {
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = $request;

            return $request->method() === 'PATCH'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response(['kind' => 'Secret'], 201);
        });

        app(RegionSecrets::class)->write($this->scope, ['DB_PASS' => 'pw-1'], ['OLD']);

        [$patch, $create] = $requests;
        $this->assertSame('PATCH', $patch->method());
        $this->assertStringEndsWith('/api/v1/namespaces/fn/secrets/orders-secret-env', $patch->url());
        $this->assertSame('application/merge-patch+json', $patch->header('Content-Type')[0]);
        $this->assertSame(['DB_PASS' => base64_encode('pw-1'), 'OLD' => null], json_decode($patch->body(), true)['data']);

        $this->assertSame('POST', $create->method());
        $created = json_decode($create->body(), true);
        $this->assertSame('orders-secret-env', $created['metadata']['name']);
        $this->assertSame('runr', $created['metadata']['labels']['app.kubernetes.io/managed-by']);
        $this->assertSame(['DB_PASS' => base64_encode('pw-1')], $created['data'], 'nothing to remove from a new Secret');
    }

    public function test_reading_tells_none_from_a_cluster_that_does_not_answer(): void
    {
        // one fake for the test (a second Http::fake() would only add stubs behind the first)
        $answer = Http::response(['kind' => 'Status', 'code' => 404], 404);
        Http::fake(function () use (&$answer) {
            return $answer;
        });
        $this->assertNull(app(RegionSecrets::class)->read($this->scope));

        $answer = Http::response(['kind' => 'Secret', 'data' => ['A' => base64_encode('x')]]);
        $this->assertSame(['A' => 'x'], app(RegionSecrets::class)->read($this->scope));
    }

    public function test_a_failure_names_the_region_never_the_value(): void
    {
        $forbidden = false;
        Http::fake(function () use (&$forbidden) {
            if (! $forbidden) {
                throw new ConnectionException('cURL error 7: Failed to connect');
            }

            return Http::response(['kind' => 'Status', 'message' => 'secrets is forbidden'], 403);
        });

        try {
            app(RegionSecrets::class)->write($this->scope, ['DB_PASS' => 'pw-never-shown']);
            $this->fail('the write should fail');
        } catch (SecretBackendException $e) {
            $this->assertSame(SecretBackendException::UNREACHABLE, $e->reason);
            $this->assertSame(503, $e->status());
            $this->assertStringContainsString('fn', $e->getMessage());
            $this->assertStringNotContainsString('pw-never-shown', $e->getMessage().($e->getPrevious()?->getMessage() ?? ''));
        }

        $forbidden = true;
        try {
            app(RegionSecrets::class)->write($this->scope, ['DB_PASS' => 'pw-never-shown']);
            $this->fail('the write should fail');
        } catch (SecretBackendException $e) {
            $this->assertStringContainsString('HTTP 403', (string) $e->getPrevious()?->getMessage());
            $this->assertStringNotContainsString('pw-never-shown', (string) $e->getPrevious()?->getMessage());
        }
    }

    public function test_a_demo_region_has_no_cluster(): void
    {
        $demo = KnativeRegion::create(['name' => 'demo', 'is_active' => true, 'is_demo' => true]);
        $project = Project::create(['name' => 'demo-app', 'service_type' => 'faas']);
        $project->forceFill(['knative_region_id' => $demo->id])->saveQuietly();

        $this->assertFalse(SecretScope::forProject($project->fresh())->hasCluster());
        $this->assertTrue($this->scope->hasCluster());
    }
}
