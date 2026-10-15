<?php

namespace Tests\Feature\Mcp\Tools;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Mcp\Tools\ToolContext;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\ExecutorImage;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Services\GitService;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\CallsMcp;

/**
 * Fixtures of the MCP tool tests (single-tenant suite): an owner, a demo region (no cluster),
 * projects of every type with a ready version, deployments, and helpers that connect a user
 * with a token of chosen abilities and call one tool. Long-running tools poll without sleeping;
 * deployment events are faked so nothing reaches a cluster.
 */
trait McpToolFixtures
{
    use ActsWithRoles, CallsMcp;

    protected User $owner;

    protected KnativeRegion $region;

    /** Runs between two polls of a long-running tool (flip a status here). */
    protected ?\Closure $onPoll = null;

    protected function setUpMcpTools(): void
    {
        $this->owner = $this->userWithRole('owner');

        config([
            'mcp.tools.poll_interval_ms' => 0,
            'mcp.tools.max_wait_seconds' => 30,
        ]);
        ToolContext::sleepUsing(function () {
            if ($this->onPoll) {
                ($this->onPoll)();
            }
        });

        Event::fake([DeploymentCreated::class, DeploymentStatusChanged::class]);

        $this->region = KnativeRegion::create([
            'name' => 'op',
            'project_namespace' => 'op',
            'is_active' => true,
            'is_demo' => true,
        ]);
    }

    protected function tearDown(): void
    {
        ToolContext::sleepUsing(null);

        parent::tearDown();
    }

    // ── Connecting ─────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} token, session */
    protected function connect(?User $user = null, array $abilities = ['*']): array
    {
        $token = $this->mcpToken($user ?? $this->owner, $abilities);

        return [$token, $this->mcpInitialize($token)];
    }

    /** One tools/call as $user with a token of $abilities. */
    protected function callTool(string $tool, array $arguments = [], ?User $user = null, array $abilities = ['*']): array
    {
        [$token, $session] = $this->connect($user, $abilities);

        return $this->mcpTool($token, $session, $tool, $arguments);
    }

    /** The raw JSON-RPC answer of a tools/call (result or error). */
    protected function rawToolCall(string $tool, array $arguments = [], ?User $user = null, array $abilities = ['*']): array
    {
        [$token, $session] = $this->connect($user, $abilities);

        return $this->mcpRpc($token, $session, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
    }

    /** A token without $tool's permissions neither sees nor calls it. */
    protected function assertToolHidden(string $tool, array $abilities, array $arguments = [], ?User $user = null): void
    {
        [$token, $session] = $this->connect($user, $abilities);

        $this->assertNotContains($tool, $this->mcpToolNames($token, $session), "{$tool} is listed for a token with ".implode(', ', $abilities));

        $answer = $this->mcpRpc($token, $session, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
        $this->assertArrayHasKey('error', $answer, "{$tool} is callable for a token with ".implode(', ', $abilities));
        $this->assertSame(-32602, $answer['error']['code']);
    }

    /** Arguments that break the input schema answer with a tool error listing the problems. */
    protected function assertSchemaRejects(string $tool, array $arguments, ?string $path = null): array
    {
        $result = $this->callTool($tool, $arguments);
        $error = $this->mcpToolError($result);

        $this->assertNotEmpty($error['problems'] ?? [], "{$tool}: schema problems expected: ".json_encode($result));
        if ($path !== null) {
            $this->assertContains($path, array_column($error['problems'], 'path'), json_encode($error['problems']));
        }

        return $error;
    }

    /** structuredContent of a successful result. */
    protected function structured(array $result): array
    {
        $this->assertFalse($result['isError'] ?? false, 'tool error: '.json_encode($result));

        return (array) ($result['structuredContent'] ?? []);
    }

    // ── Projects ───────────────────────────────────────────────────────────────

    protected function containerProject(string $name = 'shop', array $env = ['GREETING' => 'hello'], ?KnativeRegion $region = null): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => ($region ?? $this->region)->id])->saveQuietly();

        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'ports' => [8080],
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
            'trigger_type' => 'http',
            'env_vars' => $env,
        ]);

        return $project->fresh();
    }

    protected function faasProject(string $name = 'orders', array $env = ['GREETING' => 'hello']): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'faas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();

        FaasConfig::create([
            'project_id' => $project->id,
            'handler' => 'main.handler',
            'trigger_type' => 'http',
            'cpu' => '250m',
            'memory_mb' => 256,
            'timeout_seconds' => 30,
            'env_vars' => $env,
        ]);

        return $project->fresh();
    }

    protected function xaasProject(string $name = 'hello'): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'xaas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();

        XaasConfig::create([
            'project_id' => $project->id,
            'filename' => 'main.py',
            'handler' => 'handler',
            'trigger_type' => 'http',
            'cpu' => '250m',
            'memory_mb' => 256,
            'timeout_seconds' => 30,
        ]);

        return $project->fresh();
    }

    protected function version(Project $project, int $number = 1, string $status = 'ready', array $attributes = []): Version
    {
        return Version::create(array_merge([
            'project_id' => $project->id,
            'number' => $number,
            'commit_id' => 'c'.$number,
            'commit_message' => "v{$number}",
            'created_by' => 'dev',
            'build_status' => $status,
        ], $attributes));
    }

    protected function deployment(Project $project, Version $version, string $status = 'active'): Deployment
    {
        return $project->deployments()->create([
            'version_id' => $version->id,
            'status' => $status,
            'k8s_job_name' => 'run-'.$project->slug.'-v'.$version->number,
            'deployed_by' => 'dev',
        ]);
    }

    /**
     * The project's code as git returns it, and saving through a fake commit (versions numbered
     * on), so code tools run without a repository.
     *
     * @param  array<string, string>  $files
     */
    protected function fakeGit(array $files): void
    {
        $this->partialMock(GitService::class, function ($mock) use (&$files) {
            // by reference: an arrow function would capture the files before commitFiles replaced them
            $mock->shouldReceive('getAllFilesAtVersion')->andReturnUsing(function () use (&$files) {
                return $files;
            });
            $mock->shouldReceive('commitFiles')->andReturnUsing(function (Project $project, array $saved, string $message, string $userId, ?string $status = 'ready') use (&$files) {
                $files = $saved;

                return Version::create([
                    'project_id' => $project->id,
                    'number' => (int) Version::where('project_id', $project->id)->max('number') + 1,
                    'commit_id' => 'c'.uniqid(),
                    'commit_message' => $message,
                    'created_by' => $userId,
                    'changed_files' => array_keys($saved),
                    'build_status' => $status ?? 'ready',
                ]);
            });
        });
    }

    /** Active executor images of the built-in runtimes (project creation checks them). */
    protected function executorImages(): void
    {
        foreach ([['python', '3.14'], ['nodejs', '24.14']] as [$runtime, $version]) {
            ExecutorImage::updateOrCreate(['runtime' => $runtime, 'version' => $version], [
                'docker_image' => "ghcr.io/runrsh/exec-{$runtime}:{$version}",
                'is_default' => true,
                'is_active' => true,
            ]);
        }
    }
}
