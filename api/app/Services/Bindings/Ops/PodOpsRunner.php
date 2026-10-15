<?php

namespace App\Services\Bindings\Ops;

use App\Models\KnativeRegion;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\BindingManifests;
use App\Services\Bindings\BindingsCluster;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * An OpsTask as a one-shot Pod in the data namespace: a Secret with run.sh + the task's files
 * mounted at /ops, the pod runs `sh /ops/run.sh`, its log is the result. Pod and Secret are
 * deleted afterwards whatever the outcome.
 */
class PodOpsRunner implements BindingOpsRunner
{
    public function __construct(private BindingsCluster $cluster) {}

    public function run(KnativeRegion $region, OpsTask $task): OpsResult
    {
        $this->cluster->ensureDataNamespace($region);

        $k8s = $this->cluster->client($region);
        $ns = BindingsCluster::dataNamespace();
        $name = 'runr-ops-'.Str::limit(Str::slug($task->purpose), 30, '').'-'.strtolower(Str::random(6));
        $timeout = $task->timeout ?? (int) config('bindings.ops.timeout', 180);

        $secret = BindingManifests::opsSecret($name, $ns, ['run.sh' => $task->script] + $task->files);
        $pod = BindingManifests::opsPod($name, $ns, $region->registryImageRef($task->image), $name, $task->env, $timeout);

        try {
            if (! $k8s->applyResource($secret, $ns) || ! $k8s->applyResource($pod, $ns)) {
                throw new BindingException("Could not start the {$task->purpose} operation pod in {$ns}.");
            }

            $phase = 'Pending';
            $this->cluster->wait(function () use ($k8s, $name, $ns, &$phase) {
                $phase = $k8s->getResourceStatus('v1', 'Pod', $name, $ns)['status']['phase'] ?? 'Pending';

                return in_array($phase, ['Succeeded', 'Failed'], true);
            }, $timeout + 30);

            $logs = (string) $k8s->getPodLogs($name, $ns, 'ops', 2000);

            if (! in_array($phase, ['Succeeded', 'Failed'], true)) {
                Log::warning('Binding operation timed out', ['purpose' => $task->purpose, 'region' => $region->name, 'phase' => $phase]);
            }

            return new OpsResult($phase === 'Succeeded', $logs, $phase);
        } finally {
            try {
                $k8s->deleteResource('v1', 'Pod', $name, $ns);
            } catch (\Throwable) {
            }
            try {
                $k8s->deleteResource('v1', 'Secret', $name, $ns);
            } catch (\Throwable) {
            }
        }
    }
}
