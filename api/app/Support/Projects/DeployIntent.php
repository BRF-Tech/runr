<?php

namespace App\Support\Projects;

/**
 * What a deploy that ProjectAdmission is asked about will run: the production workload with the
 * deploy's overrides (deploy_config: cpu, memory_mb, min_scale, max_scale, scale_to_zero), the
 * workload of one of the project's environments (environmentId, docs/environments.md — the project
 * the guard gets is then the environment's view: its region and config), or a preview environment
 * of the project (previewId: the preview being deployed).
 */
final class DeployIntent
{
    /** @param  array<string, mixed>  $overrides */
    public function __construct(
        public readonly array $overrides = [],
        public readonly ?string $previewId = null,
        public readonly ?string $environmentId = null,
    ) {}

    /** @param  array<string, mixed>|null  $overrides */
    public static function production(?array $overrides = null, ?string $environmentId = null): self
    {
        return new self($overrides ?? [], null, $environmentId);
    }

    public static function preview(string $previewId): self
    {
        return new self([], $previewId);
    }

    public function isPreview(): bool
    {
        return $this->previewId !== null;
    }

    /** Whether it deploys an environment other than the project's own workload. */
    public function isEnvironment(): bool
    {
        return $this->environmentId !== null;
    }
}
