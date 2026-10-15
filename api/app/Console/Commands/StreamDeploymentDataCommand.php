<?php

namespace App\Console\Commands;

use App\Models\Deployment;
use App\Models\Project;
use App\Services\KnativeLogService;
use App\Services\KnativeMetricsService;
use Illuminate\Console\Command;

class StreamDeploymentDataCommand extends Command
{
    protected $signature = 'deployment:stream';

    protected $description = 'Stream deployment logs+metrics to stdout (used by LogStreamController via WS)';

    public function handle(): int
    {
        $config = json_decode(file_get_contents('php://stdin'), true);

        if (! $config || empty($config['project_id']) || empty($config['deployment_id'])) {
            $this->out(['type' => 'error', 'message' => 'Invalid config']);

            return 1;
        }

        $project = Project::find($config['project_id']);
        $deployment = Deployment::find($config['deployment_id']);

        if (! $project || ! $deployment) {
            $this->out(['type' => 'error', 'message' => 'Deployment not found']);

            return 1;
        }

        $deployment->load('version');

        $logSvc = app(KnativeLogService::class);
        $metricsSvc = app(KnativeMetricsService::class);

        $lastSince = null;
        $i = 0;

        // Initial full fetch
        $this->fetchAndEmitLogs($logSvc, $project, $deployment, $lastSince, $lastSince);
        $this->fetchAndEmitMetrics($metricsSvc, $project, $deployment, true);
        $this->fetchAndEmitMetrics($metricsSvc, $project, $deployment, false);

        while (true) {
            sleep(2);
            $i++;

            // Logs every 2s
            $stopped = $this->fetchAndEmitLogs($logSvc, $project, $deployment, $lastSince, $lastSince);
            if ($stopped) {
                break;
            }

            // Quick metrics every 6s
            if ($i % 3 === 0) {
                $this->fetchAndEmitMetrics($metricsSvc, $project, $deployment, true);
            }

            // Avg metrics every 60s
            if ($i % 30 === 0) {
                $this->fetchAndEmitMetrics($metricsSvc, $project, $deployment, false);
            }
        }

        return 0;
    }

    private function fetchAndEmitLogs(KnativeLogService $svc, Project $p, Deployment $d, ?int $since, ?int &$cursor): bool
    {
        try {
            $data = $svc->getDeploymentLogs($p, $d, $since);

            if (isset($data['suggest_status'])) {
                $this->out(['type' => 'suggest_status', 'status' => $data['suggest_status']]);

                return true;
            }

            $this->out([
                'type' => 'logs',
                'logs' => $data['logs'] ?? '',
                'pods' => $data['pods'] ?? [],
                'source' => $data['source'] ?? 'kubectl',
                'last_ts_ms' => $data['last_ts_ms'] ?? null,
            ]);

            if (isset($data['last_ts_ms'])) {
                $cursor = $data['last_ts_ms'];
            }
        } catch (\Throwable $e) {
            $this->out(['type' => 'error', 'message' => $e->getMessage()]);
        }

        return false;
    }

    private function fetchAndEmitMetrics(KnativeMetricsService $svc, Project $p, Deployment $d, bool $quick): void
    {
        try {
            $data = $svc->getDeploymentMetrics($p, $d, $quick);
            $data['type'] = $quick ? 'metrics' : 'avg_metrics';
            $this->out($data);
        } catch (\Throwable) {
            // Prometheus not configured — skip silently
        }
    }

    private function out(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE)."\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
