<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\AgentMessageHandler;
use App\Services\Helpers\AgentToken;
use App\Services\Shell\AgentController;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Reverb\Servers\Reverb\Connection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The region agent's WebSocket (/agent/{region}): a connection without the region or with a
 * token the region does not know is told why and closed; an authorized agent's messages are
 * handled one by one — a bad message (not JSON, or one the handler throws on) is logged and the
 * connection stays open for the next.
 */
class AgentSocketTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private KnativeRegion $region;

    /** @var list<string> */
    private array $sent = [];

    private ?\Closure $onMessage = null;

    private ?\Closure $onClose = null;

    private bool $closed = false;

    private bool $buffered = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = AgentToken::generate();
        $this->region = KnativeRegion::create(['name' => 'op', 'project_namespace' => 'op', 'is_active' => true]);
        $this->region->forceFill(['agent_token_hash' => AgentToken::hash($this->token)])->save();
    }

    private function connection(): Connection
    {
        return Mockery::mock(Connection::class, function (MockInterface $m) {
            $m->shouldReceive('send')->andReturnUsing(function ($message) {
                $this->sent[] = (string) $message;
            });
            $m->shouldReceive('close')->andReturnUsing(function () {
                $this->closed = true;
            });
            $m->shouldReceive('onMessage')->andReturnUsing(function (callable $callback) {
                $this->onMessage = \Closure::fromCallable($callback);
            });
            $m->shouldReceive('onClose')->andReturnUsing(function (callable $callback) {
                $this->onClose = \Closure::fromCallable($callback);
            });
            $m->shouldReceive('openBuffer')->andReturnUsing(function () {
                $this->buffered = true;
            });
        });
    }

    private function connect(string $regionId, ?string $authorization): void
    {
        $headers = $authorization === null ? [] : ['Authorization' => $authorization];
        (new AgentController)(new Request('GET', "/agent/{$regionId}", $headers), $this->connection(), $regionId);
    }

    private function deployedProject(string $slug): Project
    {
        $project = Project::create(['name' => $slug, 'service_type' => 'faas']);
        $project->forceFill(['slug' => $slug, 'knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler']);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active']);

        return $project;
    }

    public function test_a_connection_without_a_region_is_closed(): void
    {
        $this->connect('', "Bearer {$this->token}");

        $this->assertSame([json_encode(['error' => 'Missing region ID'])], $this->sent);
        $this->assertTrue($this->closed);
        $this->assertNull($this->onMessage);
    }

    public function test_a_connection_with_a_wrong_or_missing_token_is_closed(): void
    {
        foreach (['Bearer '.AgentToken::generate(), null, $this->token] as $authorization) {
            $this->sent = [];
            $this->closed = false;

            $this->connect($this->region->id, $authorization);

            $this->assertSame([json_encode(['error' => 'Unauthorized'])], $this->sent);
            $this->assertTrue($this->closed);
            $this->assertNull($this->onMessage, 'no message of it is read');
            $this->assertFalse($this->buffered);
        }
    }

    public function test_the_messages_of_an_authorized_agent_are_handled(): void
    {
        $project = $this->deployedProject('orders');

        $this->connect($this->region->id, "Bearer {$this->token}");

        $this->assertSame([], $this->sent);
        $this->assertFalse($this->closed);
        $this->assertTrue($this->buffered);
        $this->assertNotNull($this->onMessage);

        ($this->onMessage)(json_encode(['type' => 'session_start', 'project_slug' => 'orders', 'pod' => 'orders-00001-deployment-abc', 'entry_point' => 'http', 'cold_start' => true]));

        $session = DeploymentSession::sole();
        $this->assertSame($project->id, $session->project_id);
        $this->assertTrue((bool) $session->cold_start);
        $this->assertNull($session->ended_at);

        ($this->onMessage)(json_encode(['type' => 'session_end', 'project_slug' => 'orders', 'pod' => 'orders-00001-deployment-abc']));
        $this->assertNotNull($session->fresh()->ended_at);

        Log::spy();
        ($this->onClose)();
        Log::shouldHaveReceived('info')->with("Agent disconnected: region={$this->region->id}")->once();
    }

    public function test_a_bad_message_is_logged_and_the_connection_stays_open(): void
    {
        $handler = Mockery::mock(AgentMessageHandler::class);
        $handler->shouldReceive('handle')->once()->with(['type' => 'metrics'], $this->region->id)->andThrow(new \RuntimeException('database gone'));
        $this->app->instance(AgentMessageHandler::class, $handler);
        Log::spy();

        $this->connect($this->region->id, "Bearer {$this->token}");
        ($this->onMessage)('not json {');
        ($this->onMessage)(json_encode(['type' => 'metrics']));

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_starts_with($message, 'Agent message invalid JSON: not json {'))->once();
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => $message === 'Agent message error: database gone')->once();
        $this->assertFalse($this->closed);
    }
}
