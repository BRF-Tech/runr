<?php

namespace Tests\Feature;

use App\Models\ContainerConfig;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Services\Manifests\QueueConsumerManifests;
use App\Services\ProjectService;
use App\Services\RegionDemoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Region demo ("create example project"):
 * - the frontend reaches the backend through the in-cluster Service address, not the public
 *   https://<backend>.<domain> URL (no public DNS or trusted certificate needed from the pod);
 * - the backend and the stats job use the same queue stream as the email sender's consumer
 *   (QUEUE_STREAM, tenant-suffixed in multi-tenant installs);
 * - the demo PHP backend (handler.handler → handler.php) can be saved from the editor/API.
 */
class RegionDemoFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        QueueConsumerManifests::setStreamNameResolver(null);
        parent::tearDown();
    }

    private function region(): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'knative_settings' => ['knative_domain' => 'fn.example.test'],
        ]);
    }

    public function test_backend_url_is_the_in_cluster_service_even_with_a_public_domain(): void
    {
        $region = $this->region();
        $backend = Project::create(['name' => 'contact-backend', 'service_type' => 'faas', 'runtime' => 'php']);

        $this->assertSame(
            'http://contact-backend.fn.svc.cluster.local',
            app(RegionDemoService::class)->resolveBackendUrl($backend, $region),
        );
    }

    public function test_backend_and_stats_get_the_consumer_stream_name(): void
    {
        QueueConsumerManifests::setStreamNameResolver(fn (string $stream) => "{$stream}-acme");

        $frontend = Project::create(['name' => 'contact-frontend', 'service_type' => 'xaas', 'runtime' => 'nodejs']);
        $backend = Project::create(['name' => 'contact-backend', 'service_type' => 'faas', 'runtime' => 'php']);
        $stats = Project::create(['name' => 'contact-stats', 'service_type' => 'caas']);
        XaasConfig::create(['project_id' => $frontend->id, 'filename' => 'main.js', 'handler' => 'handler']);
        FaasConfig::create(['project_id' => $backend->id, 'handler' => 'handler.handler']);
        ContainerConfig::create(['project_id' => $stats->id, 'image' => 'redis:7-alpine', 'trigger_type' => 'schedule']);

        $service = app(RegionDemoService::class);
        $service->setEnvVars(
            ['frontend' => $frontend->fresh(), 'backend' => $backend->fresh(), 'stats' => $stats->fresh()],
            'http://contact-backend-acme.fn.svc.cluster.local', 'runr-redis.fn', '6379',
        );

        $expected = ['REDIS_HOST' => 'runr-redis.fn', 'REDIS_PORT' => '6379', 'QUEUE_STREAM' => 'contact-forms-acme'];
        $this->assertSame($expected, $backend->faasConfig()->first()->env_vars);
        $this->assertSame($expected, $stats->containerConfig()->first()->env_vars);
        $this->assertSame(['BACKEND_URL' => 'http://contact-backend-acme.fn.svc.cluster.local'], $frontend->xaasConfig()->first()->env_vars);
        $this->assertSame('contact-forms-acme', $service->queueStream());
    }

    public function test_demo_code_reads_the_stream_from_the_environment(): void
    {
        $service = app(RegionDemoService::class);

        $handler = $service->backendFiles('runr-redis.fn', '6379')['handler.php'];
        $this->assertStringContainsString("getenv('QUEUE_STREAM')", $handler);
        $this->assertStringContainsString("'XADD', \$stream, '*'", $handler);

        $stats = $service->statsCommand()[2];
        $this->assertStringContainsString('STREAM="${QUEUE_STREAM:-contact-forms}"', $stats);
        $this->assertStringContainsString('XLEN "$STREAM"', $stats);
        $this->assertStringNotContainsString('XLEN contact-forms', $stats);
    }

    /**
     * Neither handler pre-encodes its own `body` return value. The FaaS runtime
     * (server.php / server.js of the php and nodejs runtimes, BRF-Tech/runr-runtimes) already
     * json_encodes/JSON.stringifies a non-string body exactly once on the way out, so
     * pre-encoding it in the handler double-
     * encoded it — the frontend's backendRes.json() (and, downstream, the browser's res.json())
     * decoded only one of the two layers and got a JSON string back instead of the object.
     */
    public function test_contact_form_response_body_round_trips_through_one_json_decode(): void
    {
        $service = app(RegionDemoService::class);

        $handler = $service->backendFiles('runr-redis.fn', '6379')['handler.php'];
        $frontend = $service->frontendCode('http://contact-backend.fn.svc.cluster.local');

        $this->assertStringNotContainsString('json_encode(', $handler);
        $this->assertStringContainsString("'body' => ['message' => 'Message received', 'id' => \$id]", $handler);
        $this->assertStringContainsString("'body' => ['error' => 'All fields required']", $handler);
        $this->assertStringContainsString("'body' => ['error' => 'Internal error']", $handler);

        $this->assertStringNotContainsString('JSON.stringify(result)', $frontend);
        $this->assertStringContainsString('body: result', $frontend);
        $this->assertStringNotContainsString('JSON.stringify({ error: err.message })', $frontend);

        // Mirror the runtime's own single encode-on-the-way-out step (server.php ~line 122-124:
        // a string body is only passed through unchanged when Content-Type is NOT
        // application/json; this handler's response is always application/json — explicitly, or
        // by the runtime's own default when no headers are returned — so the runtime always
        // json_encodes the body exactly once, whether it's a string or not) to prove the fixed
        // handler's body round-trips through exactly one json_decode and yields the real message
        // — unlike the old, pre-encoded body, which still needed a second decode and left the
        // caller holding a JSON string, not an array.
        $runtimeEncode = fn ($body) => json_encode($body);

        $realBody = ['message' => 'Message received', 'id' => '1700000000000-0'];

        $fixedOnWire = $runtimeEncode($realBody);
        $fixedOnceDecoded = json_decode($fixedOnWire, true);
        $this->assertIsArray($fixedOnceDecoded);
        $this->assertSame('Message received', $fixedOnceDecoded['message']);

        $buggyPreEncodedBody = json_encode($realBody); // the old bug: handler pre-encoded body itself
        $buggyOnWire = $runtimeEncode($buggyPreEncodedBody); // runtime encodes the already-encoded string again
        $buggyOnceDecoded = json_decode($buggyOnWire, true);
        $this->assertIsString($buggyOnceDecoded, 'one decode of the old double-encoded body should still be a JSON string, not the message array');
    }

    private function faasProject(string $handler): Project
    {
        $project = Project::create(['name' => 'contact-backend', 'service_type' => 'faas', 'runtime' => 'php']);
        FaasConfig::create(['project_id' => $project->id, 'handler' => $handler]);

        return $project;
    }

    private function saveFiles(Project $project, array $files, bool $expectSave): TestResponse
    {
        $user = User::factory()->create(['username' => 'dev']);
        $this->mock(ProjectService::class, function ($mock) use ($project, $user, $expectSave) {
            if ($expectSave) {
                $mock->shouldReceive('saveFaaSFiles')->once()->andReturn(
                    Version::create(['project_id' => $project->id, 'number' => 2, 'created_by' => $user->id, 'build_status' => 'ready']),
                );
            } else {
                $mock->shouldNotReceive('saveFaaSFiles');
            }
        });

        return $this->actingAs($user, 'sanctum')->postJson("/api/projects/{$project->id}/files", [
            'files' => collect($files)->map(fn ($content, $name) => ['name' => $name, 'content' => $content])->values()->all(),
        ]);
    }

    public function test_demo_backend_files_can_be_saved(): void
    {
        $project = $this->faasProject('handler.handler');

        $files = app(RegionDemoService::class)->backendFiles('runr-redis.fn', '6379');
        $this->saveFiles($project, $files, true)->assertCreated();
    }

    public function test_handler_file_of_the_project_is_required(): void
    {
        $project = $this->faasProject('handler.handler');

        $this->saveFiles($project, ['main.php' => "<?php\nfunction handler(\$e, \$c) { return []; }\n"], false)
            ->assertUnprocessable()
            ->assertJsonPath('errors.files.0', __('api.code.main_file_delete', ['file' => 'handler.php']));
    }

    public function test_default_handler_still_needs_main_file(): void
    {
        $project = $this->faasProject('main.handler');

        $this->saveFiles($project, ['lib.php' => "<?php\n"], false)
            ->assertUnprocessable()
            ->assertJsonPath('errors.files.0', __('api.code.main_file_delete', ['file' => 'main.php']));
    }
}
