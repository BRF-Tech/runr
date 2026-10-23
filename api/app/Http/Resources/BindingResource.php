<?php

namespace App\Http\Resources;

use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Services\Bindings\BindingEnv;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A binding without its credentials (those only come from GET /bindings/{id}/credentials).
 *
 * @mixin Binding
 */
class BindingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Binding $binding */
        $binding = $this->resource;

        return [
            'id' => $binding->id,
            'name' => $binding->name,
            'resource_name' => $binding->resource_name,
            'type' => $binding->type,
            'plan' => $binding->plan,
            'knative_region_id' => $binding->knative_region_id,
            'region' => $binding->relationLoaded('region') && $binding->region
                ? ['id' => $binding->region->id, 'name' => $binding->region->name]
                : null,
            'status' => $binding->status,
            'status_message' => $binding->status_message,
            'settings' => (array) $binding->settings,
            'env_keys' => BindingEnv::keysFor($binding),
            'details' => $this->details($binding),
            'usage_bytes' => (int) $binding->usage_bytes,
            'usage_measured_at' => $binding->usage_measured_at?->toIso8601String(),
            'rotated_at' => $binding->rotated_at?->toIso8601String(),
            'delete_requested_at' => $binding->delete_requested_at?->toIso8601String(),
            'purge_at' => $binding->purge_at?->toIso8601String(),
            'created_by' => $binding->created_by,
            'created_at' => $binding->created_at?->toIso8601String(),
            'updated_at' => $binding->updated_at?->toIso8601String(),
            'attachments' => $binding->relationLoaded('attachments')
                ? $binding->attachments->map(fn (BindingAttachment $a) => self::attachment($a->setRelation('binding', $binding), false))->values()->all()
                : null,
        ];
    }

    public static function attachment(BindingAttachment $attachment, bool $withBinding = true): array
    {
        $project = $attachment->relationLoaded('project') ? $attachment->project : null;

        return array_filter([
            'id' => $attachment->id,
            'binding_id' => $attachment->binding_id,
            'project_id' => $attachment->project_id,
            'project' => $project ? ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug] : null,
            'env_prefix' => $attachment->env_prefix,
            'credential_mode' => $attachment->getAttribute('credential_mode') ?? 'static',
            // The environment the binding is attached to (docs/environments.md#bindings); null:
            // the project's own workload
            'environment_id' => $attachment->getAttribute('environment_id'),
            'environment' => $attachment->getAttribute('environment_id') && $attachment->relationLoaded('environment') ? $attachment->environment?->name : null,
            'env_names' => $attachment->binding ? $attachment->envNames() : [],
            'created_at' => $attachment->created_at?->toIso8601String(),
            'binding' => $withBinding && $attachment->binding ? (new self($attachment->binding))->resolve() : null,
        ], fn ($v) => $v !== null);
    }

    /** Non-secret facts derived from the credentials (KV mode, key scope, cluster). */
    private function details(Binding $binding): array
    {
        return match ($binding->type) {
            'kv' => ['mode' => $binding->credential('mode'), 'prefix' => $binding->credential('mode') === 'acl' ? $binding->kvPrefix() : null],
            'object' => ['bucket' => $binding->credential('bucket'), 'scoped_keys' => (bool) $binding->credential('scoped', true)],
            'postgres' => ['cluster' => $binding->credential('cluster'), 'database' => $binding->credential('database')],
            default => [],
        };
    }
}
