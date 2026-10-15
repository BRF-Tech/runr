<?php

namespace App\Jobs\Bindings;

use App\Models\Binding;
use App\Services\Bindings\BindingManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One region-side step of a binding (BindingManager job bodies): provision, rotate,
 * finish_rotation, suspend, resume, apply_settings, purge. Queued in the organization context it
 * was dispatched from (Tenancy's queue bootstrapper).
 */
class BindingOperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const OPERATIONS = ['provision', 'rotate', 'finish_rotation', 'suspend', 'resume', 'apply_settings', 'purge'];

    /** Dedicated Postgres clusters take a while to come up */
    public int $timeout = 1500;

    public int $tries = 1;

    public function __construct(
        public string $operation,
        public string $bindingId,
        public array $context = [],
    ) {}

    public function handle(BindingManager $manager): void
    {
        $binding = Binding::query()->with('region')->find($this->bindingId);
        if (! $binding) {
            return;
        }

        match ($this->operation) {
            'provision' => $binding->status === Binding::STATUS_PROVISIONING ? $manager->provision($binding) : null,
            'rotate' => $binding->status === Binding::STATUS_ROTATING ? $manager->rotate($binding) : null,
            'finish_rotation' => $manager->finishRotation($binding),
            'suspend' => $binding->status === Binding::STATUS_DELETING ? $manager->suspend($binding) : null,
            'resume' => $binding->isActive() ? $manager->resume($binding) : null,
            'apply_settings' => $manager->applySettings($binding, (array) ($this->context['old'] ?? [])),
            'purge' => $manager->purge($binding),
            default => null,
        };
    }
}
