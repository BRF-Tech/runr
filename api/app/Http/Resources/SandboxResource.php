<?php

namespace App\Http\Resources;

use App\Models\KnativeRegion;
use App\Models\Sandbox;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sandbox in the API (docs/sandbox-api.md → Sandbox object). Env values are never returned,
 * only their names; the resolved egress rules stay internal.
 *
 * @mixin Sandbox
 */
class SandboxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Sandbox $s */
        $s = $this->resource;

        // Region names, looked up once per request (lists)
        $names = $request->attributes->get('sandbox_region_names', []);
        if (! array_key_exists($s->knative_region_id, $names)) {
            $names[$s->knative_region_id] = KnativeRegion::query()->whereKey($s->knative_region_id)->value('name');
            $request->attributes->set('sandbox_region_names', $names);
        }
        $regionName = $names[$s->knative_region_id];

        return [
            'id' => $s->id,
            'status' => $s->status === Sandbox::STATUS_CLAIMING ? Sandbox::STATUS_PENDING : $s->status,
            'region' => ['id' => $s->knative_region_id, 'name' => $regionName ?: null],
            'runtime' => $s->runtime,
            'runtime_version' => $s->runtime_version,
            'image' => $s->image,
            'sandbox_class' => $s->sandbox_class,
            'isolation' => $s->isolation(),
            'resources' => [
                'cpu' => $s->cpu_m.'m',
                'cpu_m' => $s->cpu_m,
                'memory_mb' => $s->memory_mb,
                'disk_mb' => $s->disk_mb,
                'pids_limit' => $s->pids_limit,
            ],
            'network' => [
                'mode' => $s->network['mode'] ?? 'none',
                'egress_allow' => array_values($s->network['egress_allow'] ?? []),
            ],
            'read_only_root' => $s->read_only_root,
            'env_keys' => $s->envKeys(),
            'metadata' => (object) ($s->metadata ?? []),
            'ttl_seconds' => $s->ttl_seconds,
            'idle_timeout_seconds' => $s->idle_timeout_seconds,
            'expires_at' => $s->expires_at?->toIso8601String(),
            'idle_expires_at' => $s->idleExpiresAt()?->toIso8601String(),
            'last_activity_at' => $s->last_activity_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
            'started_at' => $s->started_at?->toIso8601String(),
            'paused_at' => $s->paused_at?->toIso8601String(),
            'ended_at' => $s->ended_at?->toIso8601String(),
            'end_reason' => $s->end_reason,
            'from_pool' => $s->from_pool,
            'start_ms' => $s->start_ms,
            'error' => $s->error,
            'created_by' => $s->created_by,
            'tenant_id' => $s->tenant_id,
            'urls' => ['terminal_ws' => '/ws/shell', 'exec_ws' => '/ws/sandbox-exec'],
        ];
    }
}
