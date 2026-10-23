<?php

namespace App\Triggers\Types\Sources;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Triggers\GatewayContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Object storage events (runtime/queue-consumer/sources/s3): the gateway lists a bucket prefix on
 * any S3 API (Garage, MinIO, AWS S3 …) every poll_interval and sends dev.runr.s3.object.created /
 * updated / deleted for what changed. start_from latest takes the first listing as the baseline;
 * checkpoint_key stores the listing in the bucket so changes during a gateway restart are not
 * missed (needs write access). Credentials are the trigger's secrets access_key / secret_key.
 */
class S3Trigger extends BrokerTriggerType
{
    public const EVENTS = ['created', 'updated', 'deleted'];

    public function key(): string
    {
        return 's3';
    }

    protected function configRules(): array
    {
        return [
            // host[:port], or a URL (its scheme then decides HTTPS)
            'endpoint' => ['required', 'string', 'max:255', 'regex:#^(https?://)?[A-Za-z0-9.-]+(:\d{1,5})?/?$#'],
            'secure' => 'nullable|boolean',
            'region' => 'nullable|string|max:64',
            'bucket' => 'required|string|min:3|max:63|regex:/^[a-z0-9][a-z0-9.-]+$/',
            'prefix' => 'nullable|string|max:1024',
            'path_style' => 'nullable|boolean',
            'poll_interval' => ['nullable', 'string', KafkaTrigger::DURATION],
            'events' => 'nullable|array|min:1',
            'events.*' => ['string', Rule::in(self::EVENTS)],
            'start_from' => 'nullable|string|in:earliest,latest',
            'checkpoint_key' => 'nullable|string|max:1024',
        ];
    }

    public function secretRules(): array
    {
        return [
            'access_key' => 'nullable|string|max:255',
            'secret_key' => 'nullable|string|max:1024',
        ];
    }

    public function normalize(array $config): array
    {
        $normalized = self::only($config, ['endpoint', 'region', 'bucket', 'prefix', 'poll_interval', 'checkpoint_key']);
        $normalized['secure'] = (bool) ($config['secure'] ?? true);
        $normalized['path_style'] = (bool) ($config['path_style'] ?? true);
        $events = array_values(array_intersect(self::EVENTS, (array) ($config['events'] ?? ['created', 'updated'])));
        $normalized['events'] = $events ?: ['created', 'updated'];
        $normalized['start_from'] = ($config['start_from'] ?? 'latest') === 'earliest' ? 'earliest' : 'latest';

        return self::keepDelivery($config, $normalized);
    }

    public function validate(Validator $validator, Project $project, array $config, ?ProjectTrigger $existing): void
    {
        self::requireSecret($validator, $existing, 'access_key');
        self::requireSecret($validator, $existing, 'secret_key');
    }

    protected function sourceConfig(ProjectTrigger $trigger, GatewayContext $context): array
    {
        $config = $trigger->config ?? [];

        return array_filter([
            'endpoint' => (string) ($config['endpoint'] ?? ''),
            'secure' => (bool) ($config['secure'] ?? true),
            'region' => $config['region'] ?? null,
            'bucket' => (string) ($config['bucket'] ?? ''),
            'prefix' => $config['prefix'] ?? null,
            'path_style' => (bool) ($config['path_style'] ?? true),
            'poll_interval' => $config['poll_interval'] ?? null,
            'events' => $config['events'] ?? ['created', 'updated'],
            'start_from' => $config['start_from'] ?? 'latest',
            'checkpoint_key' => $config['checkpoint_key'] ?? null,
        ], fn ($v) => $v !== null);
    }

    protected function sourceSecrets(ProjectTrigger $trigger): array
    {
        return ['access_key' => $trigger->secret('access_key'), 'secret_key' => $trigger->secret('secret_key')];
    }

    public function fields(): array
    {
        return [
            $this->field('endpoint', required: true),
            $this->field('secure', 'boolean', default: true),
            $this->field('region'),
            $this->field('bucket', required: true),
            $this->field('prefix'),
            $this->field('path_style', 'boolean', default: true),
            $this->field('access_key', required: true, secret: true),
            $this->field('secret_key', required: true, secret: true),
            $this->field('poll_interval', 'duration', default: '30s'),
            $this->field('events', 'list', default: ['created', 'updated']),
            $this->field('start_from', 'select', default: 'latest', options: ['latest', 'earliest']),
            $this->field('checkpoint_key'),
        ];
    }
}
