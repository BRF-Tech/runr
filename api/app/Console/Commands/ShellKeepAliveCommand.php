<?php

namespace App\Console\Commands;

use App\Services\InternalNetwork\InternalNetwork;
use App\Services\KubernetesClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Subprocess for ShellController — one keep-alive request to a project's Knative Service while a
 * shell into its pod is open, so the revision is not scaled to zero under the shell: a request to
 * its cluster-local host on the region Traefik's private entry point
 * (KubernetesClient::knativePrivateRequest). The revision's pods admit only Traefik and Knative
 * (RegionNetworkPolicyManifests), so the API server cannot call them directly.
 */
class ShellKeepAliveCommand extends Command
{
    protected $signature = 'shell:keep-alive
        {--kubeconfig= : Path of the region kubeconfig}
        {--serving-namespace=knative-serving : Knative Serving namespace}
        {--namespace= : Namespace of the Knative Service}
        {--service= : Knative Service name}
        {--region= : Region id (RunR signs its call on the internal network for it)}';

    protected $description = 'Keep a Knative Service from scaling to zero while a shell is open (subprocess)';

    public function handle(): int
    {
        $path = (string) $this->option('kubeconfig');
        if ($path === '' || ! is_readable($path) || ! $this->option('namespace') || ! $this->option('service')) {
            return 1;
        }

        try {
            $k8s = new KubernetesClient((string) file_get_contents($path));
            $token = $this->option('region') ? InternalNetwork::systemToken((string) $this->option('region')) : null;
            $k8s->knativePrivateRequest('GET', (string) $this->option('serving-namespace'), (string) $this->option('namespace'), (string) $this->option('service'), '', [], null, 15, $token);
        } catch (Throwable) {
            return 1;
        }

        return 0;
    }
}
