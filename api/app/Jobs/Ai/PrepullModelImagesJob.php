<?php

namespace App\Jobs\Ai;

use App\Models\KnativeRegion;
use App\Services\Ai\Models\ModelImagePrepull;
use App\Services\KubernetesClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Pre-pulls model service images on a region's nodes (ModelImagePrepull): the first run applies
 * the DaemonSet, the next ones check it every 20 s and delete it once every scheduled pod is
 * ready (each pulled the images), or at the deadline. A pre-pull is an optimisation: errors are
 * logged, never raised.
 */
class PrepullModelImagesJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const POLL_SECONDS = 20;

    /** Large CUDA images on slow links take a while; the DaemonSet never outlives this. */
    public const DEADLINE_SECONDS = 1800;

    public int $timeout = 60;

    public int $tries = 1;

    /**
     * @param  list<string>  $images
     */
    public function __construct(
        public string $regionId,
        public string $name,
        public array $images,
        public array $placement = [],
        public int $attempt = 0,
    ) {}

    public function handle(): void
    {
        $region = KnativeRegion::find($this->regionId);
        if (! $region || ! $region->kubeconfig) {
            return;
        }

        $namespace = ModelImagePrepull::namespace($region);
        $context = ['region' => $region->name, 'daemonset' => $this->name];

        try {
            $k8s = new KubernetesClient($region->kubeconfig);

            if ($this->attempt === 0) {
                if (! $k8s->getClusterResource('v1', 'Namespace', $namespace)) {
                    $k8s->applyClusterResource([
                        'apiVersion' => 'v1',
                        'kind' => 'Namespace',
                        'metadata' => ['name' => $namespace, 'labels' => ['app.kubernetes.io/managed-by' => 'runr']],
                    ]);
                }
                if (! $k8s->applyResource(ModelImagePrepull::daemonSet($region, $this->name, $this->images, $this->placement), $namespace)) {
                    throw new \RuntimeException("the DaemonSet could not be applied in {$namespace}");
                }
                Log::info('Pre-pulling model images', $context + ['images' => $this->images]);
                $this->next();

                return;
            }

            $ds = $k8s->getResourceStatus('apps/v1', 'DaemonSet', $this->name, $namespace);
            $desired = (int) ($ds['status']['desiredNumberScheduled'] ?? 0);
            $ready = (int) ($ds['status']['numberReady'] ?? 0);
            $done = $ds !== null && $desired > 0 && $ready >= $desired;

            if (! $done && $ds !== null && $this->attempt < self::maxAttempts()) {
                $this->next();

                return;
            }

            if ($ds !== null) {
                $k8s->deleteResource('apps/v1', 'DaemonSet', $this->name, $namespace);
            }
            if ($done) {
                Log::info('Model images pre-pulled', $context + ['nodes' => $ready]);
            } else {
                Log::warning('Model image pre-pull did not finish in time', $context + ['ready' => $ready, 'desired' => $desired]);
            }
        } catch (\Throwable $e) {
            Log::warning('Model image pre-pull: '.$e->getMessage(), $context + ['attempt' => $this->attempt]);
            if ($this->attempt === 0) {
                // nothing runs: the next service or storage change may try again
                ModelImagePrepull::forget($region, $this->name);
            } elseif ($this->attempt < self::maxAttempts()) {
                $this->next();
            }
        }
    }

    public static function maxAttempts(): int
    {
        return intdiv(self::DEADLINE_SECONDS, self::POLL_SECONDS);
    }

    private function next(): void
    {
        self::dispatch($this->regionId, $this->name, $this->images, $this->placement, $this->attempt + 1)
            ->delay(now()->addSeconds(self::POLL_SECONDS));
    }
}
