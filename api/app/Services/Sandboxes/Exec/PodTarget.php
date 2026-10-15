<?php

namespace App\Services\Sandboxes\Exec;

use App\Models\KnativeRegion;
use App\Models\Sandbox;
use App\Services\Sandboxes\SandboxManifests;

/** The container a command runs in: region kubeconfig, namespace, pod, container. */
final class PodTarget
{
    public function __construct(
        public readonly string $kubeconfig,
        public readonly string $namespace,
        public readonly string $pod,
        public readonly string $container = SandboxManifests::CONTAINER,
        public readonly ?string $sandboxId = null,
    ) {}

    public static function of(Sandbox $sandbox, KnativeRegion $region): self
    {
        return new self((string) $region->kubeconfig, $sandbox->namespace, $sandbox->pod_name, SandboxManifests::CONTAINER, $sandbox->id);
    }
}
