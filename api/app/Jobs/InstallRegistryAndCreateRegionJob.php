<?php

namespace App\Jobs;

use App\Events\PlatformEvent;
use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\Helpers\MonitoringEndpointAccess;
use App\Services\KnativeNodeService;
use App\Services\KnativeRedisService;
use App\Services\KnativeStatusService;
use App\Services\RegionRegistrySetup;
use App\Services\ZotRegistryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Encrypted queue payload: carries the kubeconfig and registry / S3 credentials (docs/security.md) */
class InstallRegistryAndCreateRegionJob implements ShouldBeEncrypted, ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The PVC test (knative.registry_pvc_test_seconds, 30 to 900 s) and 720 s for the rest of the
     * setup: Zot's readiness (120 s), the node mirror (120 s), the image pre-pull (300 s), and 180 s
     * for Helm and the API calls. Below Horizon's supervisor timeout even at the longest PVC test.
     */
    public int $timeout;

    /**
     * One try: the setup installs Zot and creates the region — the queue default of several
     * immediate re-runs repeated a half-done install. Errors inside handle() are reported to
     * the setup wizard; failed() covers what escapes (timeout, worker killed).
     */
    public int $tries = 1;

    public function __construct(
        private string $token,
        private array $config,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
        $this->timeout = (int) config('knative.registry_pvc_test_seconds', 180) + 720;
    }

    /**
     * The public id of a setup in its broadcasts (registry.progress, region.created,
     * region.create_failed): the console matches events to the setup it started by this id
     * (POST /registry-setup answers it next to setup_token). The setup token itself stays out of
     * the platform channel — it authorizes status and retry, and SecretRedactor masks any
     * `token` key there anyway.
     */
    public static function setupId(string $token): string
    {
        return substr(hash('sha256', 'registry-setup:'.$token), 0, 32);
    }

    public function handle(ZotRegistryService $zot, KnativeStatusService $statusService, KnativeNodeService $nodeService): void
    {
        $key = "registry_setup:{$this->token}";

        try {
            // 1–3. Test the storage / the external registry, label the workers, install Zot
            $setup = (new RegionRegistrySetup($zot, $nodeService))->run(
                $this->config['kubeconfig'],
                $this->config,
                $this->config['nodes'] ?? [],
                fn (string $phase) => $this->updateCache($key, $phase === 'zot_install' ? 'installing' : 'testing', $phase),
            );
            if (! $setup['ok']) {
                $this->failCache($key, $setup['phase'], $setup['message']);

                return;
            }
            $registryAddress = $setup['address'];

            // 4. Create region
            $this->updateCache($key, 'installing', 'region_create');
            $region = $this->createRegion($statusService, $nodeService, $registryAddress);

            Cache::put($key, [
                'status' => 'ready',
                'phase' => 'done',
                'message' => 'Registry and region created successfully.',
                'region_id' => $region->id,
                'address' => $registryAddress ?? null,
            ], now()->addMinutes(30));

            $this->broadcastSuccess('region.created', [
                'setup_id' => self::setupId($this->token),
                'region_id' => $region->id,
                'region_name' => $region->name,
            ], "Region {$region->name} created");

        } catch (\Throwable $e) {
            Log::error('Registry setup failed', ['setup_id' => self::setupId($this->token), 'error' => $e->getMessage()]);
            $current = Cache::get($key, []);
            Cache::put($key, array_merge($current, [
                'status' => 'error',
                'message' => $e->getMessage(),
            ]), now()->addMinutes(30));

            $this->broadcastFailure('region.create_failed', [
                'setup_id' => self::setupId($this->token),
            ], $e, 'Region creation failed');
        }
    }

    public function failed(?\Throwable $e): void
    {
        $key = "registry_setup:{$this->token}";
        $current = Cache::get($key, []);

        if (($current['status'] ?? null) === 'ready') {
            return;
        }

        Cache::put($key, array_merge($current, [
            'status' => 'error',
            'message' => 'Region setup did not finish: '.($e?->getMessage() ?? 'unknown error'),
        ]), now()->addMinutes(30));

        Log::error('Registry setup job failed', ['setup_id' => self::setupId($this->token), 'error' => $e?->getMessage()]);

        $this->broadcastFailure('region.create_failed', [
            'setup_id' => self::setupId($this->token),
        ], $e ?? new \RuntimeException('unknown error'), 'Region creation failed');
    }

    private function createRegion(KnativeStatusService $statusService, KnativeNodeService $nodeService, ?string $registryAddress): KnativeRegion
    {
        $projectNs = $this->config['name'];
        $nodeNames = $this->config['nodes'] ?? [];

        $regionData = [
            'name' => $this->config['name'],
            'kubeconfig' => $this->config['kubeconfig'],
            'project_namespace' => $projectNs,
        ] + array_filter(RegionRegistrySetup::regionAttributes($this->config, $registryAddress), fn ($value) => $value !== null);

        // Optional operator-supplied Prometheus/Loki (validated by RegistrySetupRequest): stored
        // with the managed flag off so installServing() does not install RunR's own stack
        $regionData += MonitoringEndpointAccess::regionAttributes($this->config);

        // Region mode chosen in the wizard (only where the table has the column, Tenancy module)
        if (! empty($this->config['region_mode']) && KnativeRegion::hasRegionMode()) {
            $regionData['region_mode'] = $this->config['region_mode'];
        }

        $region = KnativeRegion::create($regionData);

        $statusService->createProjectNamespace($region, $projectNs);

        if (! empty($nodeNames)) {
            try {
                $nodeService->applyWorkerSelection($region, $nodeNames);
            } catch (\Throwable $e) {
                Log::warning('Worker selection failed during registry setup', ['error' => $e->getMessage()]);
            }
        }

        app(KnativeRedisService::class)->queueInitialInstall(
            $region,
            $this->config['redis_mode'] ?? null,
            $this->config['redis_address'] ?? null,
            $this->config['redis_password'] ?? null,
            $this->initiatorId,
        );

        return $region;
    }

    private const PHASE_MESSAGES = [
        'pvc_test' => 'Testing PVC provisioning...',
        's3_test' => 'Testing S3 connection...',
        'registry_test' => 'Testing registry connection...',
        'zot_install' => 'Installing Zot registry...',
        'region_create' => 'Creating region...',
    ];

    private function updateCache(string $key, string $status, string $phase): void
    {
        $data = [
            'status' => $status,
            'phase' => $phase,
            'message' => self::PHASE_MESSAGES[$phase] ?? '',
            'region_id' => null,
            'address' => null,
        ];

        Cache::put($key, $data, now()->addMinutes(30));

        event(new PlatformEvent('registry.progress', [
            'setup_id' => self::setupId($this->token),
            'status' => $status,
            'phase' => $phase,
            'message' => $data['message'],
        ]));
    }

    /**
     * A storage / registry test that failed: recorded for the status endpoint and broadcast like
     * every other failure, so the setup wizard (which follows the setup by its events) stops
     * waiting and shows the error.
     */
    private function failCache(string $key, string $phase, string $message): void
    {
        Cache::put($key, [
            'status' => 'error',
            'phase' => $phase,
            'message' => $message,
            'region_id' => null,
            'address' => null,
        ], now()->addMinutes(30));

        $this->broadcastFailure('region.create_failed', [
            'setup_id' => self::setupId($this->token),
            'phase' => $phase,
        ], new \RuntimeException($message), 'Region creation failed');
    }
}
