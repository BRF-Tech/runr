<?php

namespace App\Support\Projects;

/**
 * What a deploy that ProjectAdmission is asked about will run: the production workload with the
 * deploy's overrides (deploy_config: cpu, memory_mb, min_scale, max_scale, scale_to_zero), or a
 * preview environment of the project (previewId: the preview being deployed).
 */
final class DeployIntent
{
    /** @param  array<string, mixed>  $overrides */
    public function __construct(
        public readonly array $overrides = [],
        public readonly ?string $previewId = null,
    ) {}

    /** @param  array<string, mixed>|null  $overrides */
    public static function production(?array $overrides = null): self
    {
        return new self($overrides ?? []);
    }

    public static function preview(string $previewId): self
    {
        return new self([], $previewId);
    }

    public function isPreview(): bool
    {
        return $this->previewId !== null;
    }
}
