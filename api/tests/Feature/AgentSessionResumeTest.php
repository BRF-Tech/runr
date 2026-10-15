<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\AgentMessageHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The region agent reports session_start for every running pod again after it restarts or
 * re-establishes its pod watch (runtime/agent session Tracker.reconcile). AgentMessageHandler
 * continues the pod's open session instead of starting a second one.
 */
class AgentSessionResumeTest extends TestCase
{
    use RefreshDatabase;

    private const KUBECONFIG = "apiVersion: v1\nclusters:\n- cluster:\n    server: https://127.0.0.1:6443\n  name: c\ncontexts:\n- context:\n    cluster: c\n    user: u\n  name: c\ncurrent-context: c\nusers:\n- name: u\n  user:\n    token: t\n";

    private KnativeRegion $region;

    private Project $project;

    private Deployment $deployment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = KnativeRegion::create(['name' => 'op', 'project_namespace' => 'op', 'kubeconfig' => self::KUBECONFIG, 'is_active' => true]);
        $project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler']);

        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $this->deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active']);
        $this->project = $project->fresh();
    }

    private function send(string $type, string $pod): void
    {
        app(AgentMessageHandler::class)->handle([
            'type' => $type,
            'project_slug' => $this->project->slug,
            'project_id' => $this->project->id,
            'entry_point' => 'http',
            'pod' => $pod,
            'cold_start' => true,
        ], $this->region->id);
    }

    public function test_a_repeated_start_of_the_same_pod_resumes_its_open_session(): void
    {
        $this->send('session_start', 'orders-00001-deployment-abc');
        $this->send('session_start', 'orders-00001-deployment-abc');

        $sessions = DeploymentSession::where('deployment_id', $this->deployment->id)->get();
        $this->assertCount(1, $sessions);
        $this->assertNull($sessions->first()->ended_at);
        $this->assertSame('orders-00001-deployment-abc', $sessions->first()->metadata['pod'] ?? null);
    }

    public function test_the_session_is_found_by_pod_name_when_the_cache_entry_is_gone(): void
    {
        $this->send('session_start', 'orders-00001-deployment-abc');
        Cache::flush(); // API restart / expired entry

        $this->send('session_start', 'orders-00001-deployment-abc');
        $this->assertSame(1, DeploymentSession::where('deployment_id', $this->deployment->id)->count());

        Cache::flush();
        $this->send('session_end', 'orders-00001-deployment-abc');
        $this->assertNotNull(DeploymentSession::where('deployment_id', $this->deployment->id)->first()->ended_at, 'the end finds the session by pod name');
    }

    public function test_another_pod_and_a_start_after_the_end_get_their_own_sessions(): void
    {
        $this->send('session_start', 'orders-00001-deployment-abc');
        $this->send('session_start', 'orders-00001-deployment-def');
        $this->assertSame(2, DeploymentSession::where('deployment_id', $this->deployment->id)->count());

        $this->send('session_end', 'orders-00001-deployment-abc');
        $this->send('session_start', 'orders-00001-deployment-abc');

        $this->assertSame(3, DeploymentSession::where('deployment_id', $this->deployment->id)->count());
        $this->assertSame(2, DeploymentSession::where('deployment_id', $this->deployment->id)->whereNull('ended_at')->count());
    }
}
