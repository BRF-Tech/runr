<?php

namespace App\Mcp\Tools\Catalog\Models;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ModelServiceDeploy extends Tool
{
    use ReadsProjects;

    public function name(): string
    {
        return 'model_service_deploy';
    }

    public function title(): string
    {
        return 'Deploy a model service';
    }

    public function description(): string
    {
        return 'Deploys a Hugging Face model (Hub id such as "Qwen/Qwen2.5-0.5B-Instruct", or a model already in RunR) as a model service in a region and waits until it is ready or failed, reporting progress. RunR picks the engine and size unless given; "offline" mode serves a copy mirrored into the region. Then model_invoke tries it and model_bind gives a project access.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'hub_id' => Schema::string('Hugging Face model id ("org/name").', ['maxLength' => 200]),
            'model' => Schema::string('Id of a model already in RunR (instead of hub_id).', ['maxLength' => 64]),
            'revision' => Schema::string('Hub revision (branch, tag or commit).', ['maxLength' => 100, 'pattern' => '^[A-Za-z0-9._/-]+$']),
            'region' => Schema::string('Region id or name.', ['maxLength' => 64]),
            'name' => Schema::string('Service name: lower-case letters, digits and dashes.', ['maxLength' => 30, 'pattern' => '^[a-z]([-a-z0-9]{0,28}[a-z0-9])?$']),
            'mode' => Schema::enum(['online', 'offline'], 'online: weights from the Hub at start; offline: a copy mirrored into the region.'),
            'device' => Schema::enum(['cpu', 'gpu'], 'Where it runs (default: what the model and region allow).'),
            'engine' => Schema::string('Serving engine ("auto" or an engine id; default auto).', ['maxLength' => 32]),
            'gpu_count' => Schema::integer('GPUs per replica (device gpu).', 1, 8),
            'min_scale' => Schema::integer('Minimum replicas.', 0, 10),
            'wait' => Schema::boolean('Wait until the service is ready (default true).', true),
            'timeout_seconds' => Schema::integer('Longest wait in seconds.', 1, 3600),
        ]);
    }

    public function permissions(): array
    {
        return ['models.deploy'];
    }

    public function group(): string
    {
        return 'models';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function streams(): bool
    {
        return true;
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        if (empty($arguments['hub_id']) && empty($arguments['model'])) {
            throw new ToolError('Pass hub_id (a Hugging Face model id) or model (a model in RunR).');
        }

        $payload = array_filter([
            'hub_id' => $arguments['hub_id'] ?? null,
            'ai_model_id' => $arguments['model'] ?? null,
            'revision' => $arguments['revision'] ?? null,
            'name' => $arguments['name'] ?? null,
            'knative_region_id' => $this->regionId($context, $arguments['region'] ?? null),
            'mode' => $arguments['mode'] ?? null,
            'device' => $arguments['device'] ?? null,
            'engine' => $arguments['engine'] ?? null,
            'min_scale' => $arguments['min_scale'] ?? null,
            'gpu' => isset($arguments['gpu_count']) ? ['count' => (int) $arguments['gpu_count']] : null,
            'deploy' => true,
        ], fn ($v) => $v !== null);

        $context->progress(0, null, 'Creating the model service…');
        $result = $context->api()->post('/model-services', $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The model service was not created');
        }

        $service = (array) $result->data();
        $context->setTarget('model_service', (string) ($service['id'] ?? ''), (string) ($service['name'] ?? ''));

        if ($arguments['wait'] ?? true) {
            $deadline = time() + $context->maxWait(isset($arguments['timeout_seconds']) ? (int) $arguments['timeout_seconds'] : null);
            $step = 0;
            while (in_array($service['status'] ?? null, ['deploying', 'waiting_snapshot'], true)) {
                $context->progress(++$step, null, 'Model service '.($service['status'] ?? '').(! empty($service['status_message']) ? ': '.$service['status_message'] : '').'…');
                if (time() >= $deadline || ! $context->pause()) {
                    break;
                }
                $fresh = $context->api()->get(InternalApi::path('model-services', (string) $service['id']));
                if (! $fresh->ok()) {
                    throw ToolError::fromApi($fresh, 'Reading the model service');
                }
                $service = (array) $fresh->data();
            }
        }

        $summary = ['service' => ModelServicesList::summary($service)];

        return match ($service['status'] ?? null) {
            'ready' => ToolResult::ok($summary, 'Model service '.($service['name'] ?? '?').' is ready.'),
            'failed' => ToolResult::error('The model service failed: '.($service['status_message'] ?? 'no details').'.', $summary),
            default => ToolResult::ok($summary, 'Model service '.($service['name'] ?? '?').' is '.($service['status'] ?? 'deploying').'; model_services_list follows it.'),
        };
    }
}
