<?php

namespace Tests\Feature\Mcp\Tools;

use App\Services\KnativeLogService;
use App\Services\MetricsPersistenceService;
use App\Services\SessionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * logs_tail (the deployment logs route, its text parsed into lines and filtered) and
 * metrics_summary (the metrics summary route).
 */
class OperationsToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    /** @var list<?int> since (ms) the logs route was asked for */
    private array $askedSince = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
    }

    private function fakeLogs(string $text, string $source = 'loki'): void
    {
        $this->mock(KnativeLogService::class, function ($mock) use ($text, $source) {
            $mock->shouldReceive('getDeploymentLogs')->andReturnUsing(function ($project, $deployment, $sinceMs) use ($text, $source) {
                $this->askedSince[] = $sinceMs;

                return [
                    'pods' => [['name' => 'shop-00001-deployment-abc', 'status' => 'Running', 'ready' => true]],
                    'logs' => $text,
                    'last_ts_ms' => 1760000003000,
                    'source' => $source,
                ];
            });
        });
    }

    private function deployedShop(): void
    {
        $project = $this->containerProject('shop');
        $this->deployment($project, $this->version($project, 1), 'active');
    }

    // ── logs_tail ──────────────────────────────────────────────────────────────

    public function test_the_newest_lines_of_the_current_deployment(): void
    {
        $this->deployedShop();
        $this->fakeLogs("=== shop-00001-deployment-abc ===\n1760000001000\tstarting\n1760000002000\tGET / 200\n1760000003000\tERROR database unreachable\n");

        $result = $this->structured($this->callTool('logs_tail', ['project' => 'shop', 'lines' => 2]));

        $this->assertSame(['GET / 200', 'ERROR database unreachable'], array_column($result['lines'], 'line'));
        $this->assertSame('shop-00001-deployment-abc', $result['lines'][0]['pod']);
        $this->assertSame(1760000003000, $result['last_ts_ms']);
        $this->assertSame('loki', $result['source']);
    }

    public function test_lines_are_filtered_by_text_errors_and_time(): void
    {
        $this->deployedShop();
        $this->fakeLogs("=== pod ===\n1760000001000\tstarting\n1760000002000\tTraceback (most recent call last)\n1760000003000\tready to serve\n");

        $errors = $this->structured($this->callTool('logs_tail', ['project' => 'shop', 'errors_only' => true]));
        $this->assertSame(['Traceback (most recent call last)'], array_column($errors['lines'], 'line'));

        $ready = $this->structured($this->callTool('logs_tail', ['project' => 'shop', 'contains' => 'READY']));
        $this->assertSame(['ready to serve'], array_column($ready['lines'], 'line'));

        $since = $this->structured($this->callTool('logs_tail', ['project' => 'shop', 'since' => '1760000002500']));
        $this->assertSame(['ready to serve'], array_column($since['lines'], 'line'));
        $this->assertContains(1760000002500, $this->askedSince, 'the route reads only newer lines');
    }

    public function test_plain_kubectl_output_is_read_too(): void
    {
        $this->deployedShop();
        $this->fakeLogs("=== shop-abc (Running) ===\nhello from kubectl\n", 'kubectl');

        $result = $this->structured($this->callTool('logs_tail', ['project' => 'shop']));

        $this->assertSame([['ts' => null, 'pod' => 'shop-abc', 'line' => 'hello from kubectl']], $result['lines']);
    }

    public function test_logs_errors_and_permissions(): void
    {
        $this->containerProject('shop');

        $none = $this->mcpToolError($this->callTool('logs_tail', ['project' => 'shop']));
        $this->assertStringContainsString('deploy', $none['message']);

        $this->deployedShop2();
        $this->mcpToolError($this->callTool('logs_tail', ['project' => 'live', 'since' => 'yesterday-ish']));
        $this->assertSchemaRejects('logs_tail', ['project' => 'live', 'lines' => 100000], '/lines');
        $this->assertToolHidden('logs_tail', ['projects.view', 'metrics.view']);
    }

    private function deployedShop2(): void
    {
        $project = $this->containerProject('live');
        $this->deployment($project, $this->version($project, 1), 'active');
        $this->fakeLogs('');
    }

    // ── metrics_summary ────────────────────────────────────────────────────────

    public function test_the_metrics_summary_of_a_period(): void
    {
        $this->containerProject('shop');
        $this->mock(MetricsPersistenceService::class, fn ($m) => $m->shouldReceive('getSummary')->withArgs(fn ($p, $days) => $days === 30)->andReturn(['avg_cpu_m' => 120, 'peak_memory_mb' => 300]));
        $this->mock(SessionTrackingService::class, fn ($m) => $m->shouldReceive('getStats')->andReturn(['sessions' => 4, 'cold_starts' => 1]));

        $result = $this->structured($this->callTool('metrics_summary', ['project' => 'shop', 'period' => '30d']));

        $this->assertSame('30d', $result['period']);
        $this->assertSame(120, $result['metrics']['avg_cpu_m']);
        $this->assertSame(4, $result['sessions']['sessions']);
    }

    public function test_metrics_validation_and_permissions(): void
    {
        $this->containerProject('shop');

        $this->assertSchemaRejects('metrics_summary', ['project' => 'shop', 'period' => '1y'], '/period');
        $this->assertToolHidden('metrics_summary', ['projects.view', 'logs.view']);
    }
}
