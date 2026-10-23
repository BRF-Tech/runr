<?php

namespace App\Mcp\Tools\Catalog\Projects;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ProjectUpdate extends Tool
{
    use ReadsProjects;

    /** Arguments that go into the project config, argument => config field. */
    private const CONFIG_FIELDS = [
        'cpu' => 'cpu', 'memory_mb' => 'memory_mb', 'timeout_seconds' => 'timeout_seconds',
        'scale_to_zero' => 'scale_to_zero', 'min_scale' => 'min_scale', 'max_scale' => 'max_scale',
        'trigger' => 'trigger_type', 'schedule' => 'schedule', 'schedule_timezone' => 'schedule_timezone',
        'queue_stream' => 'queue_stream', 'image' => 'image', 'command' => 'command', 'handler' => 'handler',
    ];

    public function name(): string
    {
        return 'project_update';
    }

    public function title(): string
    {
        return 'Change a project';
    }

    public function description(): string
    {
        return 'Changes a project\'s description, region, size (cpu, memory, timeout), scaling, the timed warmer (scale_schedule), trigger or — for caas — image, port and command. A config change saves a new version; it reaches the workload with the next deploy — a changed scale_schedule alone is applied to the running workload within a minute without one. Environment variables: env_set / env_unset.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'description' => Schema::string('New description.', ['maxLength' => 2000]),
            'region' => Schema::string('Move the project to this region (id or name).', ['maxLength' => 64]),
            'cpu' => Schema::string('CPU, e.g. "500m".', ['maxLength' => 16]),
            'memory_mb' => Schema::integer('Memory in MiB.', 64, 262144),
            'timeout_seconds' => Schema::integer('Request timeout in seconds.', 1, 3600),
            'scale_to_zero' => Schema::boolean('Scale to zero when idle.'),
            'min_scale' => Schema::integer('Minimum replicas.', 0, 10),
            'max_scale' => Schema::integer('Maximum replicas.', 1, 1000),
            'scale_schedule' => ProjectCreate::scaleScheduleSchema('The rows replace the whole schedule; [] or null turns the timed warmer off.'),
            'trigger' => Schema::enum(['http', 'schedule', 'queue', 'workflow'], 'New trigger.'),
            'schedule' => Schema::string('Cron expression (trigger schedule).', ['maxLength' => 100]),
            'schedule_timezone' => Schema::string('IANA time zone of the schedule trigger (e.g. Europe/Berlin); "" = the organization\'s time zone, which it then follows.', ['maxLength' => 64]),
            'queue_stream' => Schema::string('Redis stream (trigger queue).', ['maxLength' => 255, 'pattern' => '^[a-zA-Z0-9._-]+$']),
            'handler' => Schema::string('FaaS entry point <file>.<function>.', ['maxLength' => 128]),
            'image' => Schema::string('New container image (caas).', ['maxLength' => 500]),
            'port' => Schema::integer('Container port (caas).', 1, 65535),
            'command' => Schema::array(['type' => 'string', 'maxLength' => 1000], 'Container command (caas).', 32),
            'message' => Schema::string('Message of the version the change saves.', ['maxLength' => 500]),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['projects.update|env.edit'];
    }

    public function group(): string
    {
        return 'projects';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $payload = [];
        if (array_key_exists('description', $arguments)) {
            $payload['description'] = (string) $arguments['description'];
        }
        if (! empty($arguments['region'])) {
            $payload['knative_region_id'] = $this->regionId($context, (string) $arguments['region']);
        }

        $config = [];
        foreach (self::CONFIG_FIELDS as $argument => $field) {
            if (array_key_exists($argument, $arguments)) {
                $config[$field] = $arguments[$argument];
            }
        }
        if (array_key_exists('scale_schedule', $arguments)) {
            // null and [] both turn the timed warmer off (the API keeps no schedule)
            $config['scale_schedule'] = ProjectCreate::scaleScheduleRows($arguments['scale_schedule']);
        }
        if (isset($arguments['port'])) {
            $config['ports'] = [(int) $arguments['port']];
        }
        if ($config !== []) {
            $payload['config'] = $config;
        }
        if (! empty($arguments['message'])) {
            $payload['config_message'] = (string) $arguments['message'];
        }

        if (! isset($payload['description']) && ! isset($payload['knative_region_id']) && ! isset($payload['config'])) {
            throw new ToolError('Nothing to change: pass at least one field (description, region, cpu, memory_mb, scale_schedule, trigger, image, …).');
        }

        $result = $context->api()->put(InternalApi::path('projects', $projectId), $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The project was not changed');
        }

        $project = (array) $result->data();
        $changed = array_merge(array_keys(array_diff_key($payload, ['config' => 1, 'config_message' => 1])), array_keys($config));

        return ToolResult::ok([
            'project' => ProjectsList::summary($project),
            'changed' => $changed,
        ], 'Changed '.implode(', ', $changed).'.'.match (true) {
            ! isset($payload['config']) => '',
            // The timed warmer sets the running workload's warm containers itself (no new revision)
            array_keys($config) === ['scale_schedule'] => ' The timed warmer applies the schedule to the running workload within a minute.',
            default => ' Deploy to apply the new configuration.',
        });
    }
}
