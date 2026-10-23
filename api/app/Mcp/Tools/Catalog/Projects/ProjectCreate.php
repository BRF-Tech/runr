<?php

namespace App\Mcp\Tools\Catalog\Projects;

use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ProjectCreate extends Tool
{
    use ReadsProjects;

    public function name(): string
    {
        return 'project_create';
    }

    public function title(): string
    {
        return 'Create a project';
    }

    public function description(): string
    {
        return 'Creates a project in a region. Types: "xaas" — a single-file function (handler in one file), "faas" — a multi-file project with a dependency file (requirements.txt, package.json, …; RunR builds an image when needed), "caas" — any container image. Code projects take a runtime (runtimes_list shows the catalog). The new project has no code version yet: call code_write_files (xaas/faas), then deploy. A CaaS project has its first version at once and can be deployed right away. A global project (regions: two or more regions with global DNS) runs in all of them behind one host name <slug>.<zone>; region is then its primary, mode ha (default) or proximity. Any trigger: HTTP and workflow triggers answer in every region, the others run in the primary only.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'name' => Schema::string('Project name; becomes the URL slug (lower-case letters, digits and dashes, starting with a letter).', ['minLength' => 1, 'maxLength' => 44, 'pattern' => '^[A-Za-z][A-Za-z0-9 _-]*$']),
            'service_type' => Schema::enum(['xaas', 'faas', 'caas'], 'Project type.'),
            'region' => Schema::string('Region id or name (regions_list). Optional when only one region is available.', ['maxLength' => 64]),
            'runtime' => Schema::string('Runtime id for xaas / faas (python, nodejs, go, php, …; runtimes_list). Default python.', ['maxLength' => 32]),
            'runtime_version' => Schema::string('Runtime version; default: the runtime\'s default version.', ['maxLength' => 32]),
            'description' => Schema::string('Free text.', ['maxLength' => 2000]),
            'handler' => Schema::string('FaaS entry point as <file>.<function> (default main.handler).', ['maxLength' => 128]),
            'trigger' => Schema::enum(['http', 'schedule', 'queue', 'workflow'], 'How the project is invoked (default http).', 'http'),
            'schedule' => Schema::string('Cron expression, required for trigger schedule ("*/5 * * * *").', ['maxLength' => 100]),
            'schedule_timezone' => Schema::string('IANA time zone of the schedule (e.g. Europe/Berlin); default the organization\'s time zone, which it then follows.', ['maxLength' => 64]),
            'queue_stream' => Schema::string('Redis stream name, required for trigger queue.', ['maxLength' => 255, 'pattern' => '^[a-zA-Z0-9._-]+$']),
            'image' => Schema::string('Container image, required for caas ("ghcr.io/acme/api:1.2").', ['maxLength' => 500]),
            'port' => Schema::integer('Port the container listens on (caas, http trigger).', 1, 65535),
            'command' => Schema::array(['type' => 'string', 'maxLength' => 1000], 'Container command (caas).', 32),
            'cpu' => Schema::string('CPU, e.g. "250m" or "0.5".', ['maxLength' => 16]),
            'memory_mb' => Schema::integer('Memory in MiB.', 64, 262144),
            'timeout_seconds' => Schema::integer('Request timeout in seconds.', 1, 3600),
            'scale_to_zero' => Schema::boolean('Scale to zero replicas when idle.'),
            'min_scale' => Schema::integer('Minimum replicas.', 0, 10),
            'max_scale' => Schema::integer('Maximum replicas.', 1, 1000),
            'scale_schedule' => self::scaleScheduleSchema('Omit it for a fixed min_scale.'),
            'env' => Schema::map(['type' => 'string', 'maxLength' => 32768], 'Plain environment variables, NAME → value. Secret values: env_set with secret true.'),
            'regions' => Schema::array(['type' => 'string', 'maxLength' => 64], 'Global project: every region it runs in (ids or names, 2 or more, with global DNS); region is the primary (default: the first).', 16),
            'mode' => Schema::enum(['ha', 'proximity'], 'Global project: ha — the primary region, failing over to the others; proximity — the closest healthy region.', 'ha'),
        ], ['name', 'service_type']);
    }

    public function permissions(): array
    {
        return ['projects.create'];
    }

    public function group(): string
    {
        return 'projects';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $type = (string) $arguments['service_type'];
        $trigger = (string) ($arguments['trigger'] ?? 'http');

        if ($type === 'caas' && empty($arguments['image'])) {
            throw new ToolError('A caas project needs an image.');
        }

        $config = self::compact([
            'trigger_type' => $trigger,
            'schedule' => $arguments['schedule'] ?? null,
            'schedule_timezone' => $arguments['schedule_timezone'] ?? null,
            'queue_stream' => $arguments['queue_stream'] ?? null,
            'handler' => $type === 'faas' ? ($arguments['handler'] ?? null) : null,
            'cpu' => $arguments['cpu'] ?? null,
            'memory_mb' => $arguments['memory_mb'] ?? null,
            'timeout_seconds' => $arguments['timeout_seconds'] ?? null,
            'min_scale' => $arguments['min_scale'] ?? null,
            'max_scale' => $arguments['max_scale'] ?? null,
            'env_vars' => $arguments['env'] ?? null,
        ]);
        if (array_key_exists('scale_to_zero', $arguments)) {
            $config['scale_to_zero'] = (bool) $arguments['scale_to_zero'];
        }
        if (($rows = self::scaleScheduleRows($arguments['scale_schedule'] ?? null)) !== []) {
            $config['scale_schedule'] = $rows;
        }
        if ($type === 'caas') {
            $config['image'] = (string) $arguments['image'];
            if (isset($arguments['port']) && $trigger !== 'schedule') {
                $config['ports'] = [(int) $arguments['port']];
            }
            if (! empty($arguments['command'])) {
                $config['command'] = array_values($arguments['command']);
            }
        }

        // Global project (docs/global-projects.md): the primary is region, else the first of regions
        $global = null;
        if (! empty($arguments['regions'])) {
            $regionIds = array_values(array_map(fn ($r) => $this->regionId($context, (string) $r), (array) $arguments['regions']));
            $primary = isset($arguments['region']) ? $this->regionId($context, (string) $arguments['region']) : $regionIds[0];
            $global = [
                'placement' => 'global',
                'global_mode' => (string) ($arguments['mode'] ?? 'ha'),
                'region_ids' => array_values(array_unique(array_merge([$primary], $regionIds))),
            ];
        }

        $payload = self::compact([
            'name' => (string) $arguments['name'],
            'service_type' => $type,
            'description' => $arguments['description'] ?? null,
            'knative_region_id' => $global ? $global['region_ids'][0] : $this->regionId($context, $arguments['region'] ?? null),
            'runtime' => $type === 'caas' ? null : (string) ($arguments['runtime'] ?? 'python'),
            'runtime_version' => $type === 'caas' ? null : ($arguments['runtime_version'] ?? null),
        ]);
        $payload['config'] = $config === [] ? new \stdClass : $config;
        if ($global) {
            $payload += $global;
        }

        $result = $context->api()->post('/projects', $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The project was not created');
        }

        $project = (array) $result->data();
        $context->setTarget('project', (string) ($project['id'] ?? ''), (string) ($project['name'] ?? ''));

        $next = $type === 'caas'
            ? 'Deploy it with deploy.'
            : 'Write its code with code_write_files, then deploy.';

        return ToolResult::ok(['project' => ProjectsList::summary($project)], "Project {$project['name']} created. {$next}");
    }

    /**
     * Input schema of config.scale_schedule, the timed warmer (docs/mcp.md): rows of a five-field
     * cron expression in the organization's time zone and the containers kept warm from that moment
     * until the next row fires. The API validates the rows (cron syntax, min_scale ≤ max_scale).
     */
    public static function scaleScheduleSchema(string $note): array
    {
        return [
            'type' => ['array', 'null'],
            'description' => 'Timed warmer: each row keeps at least min_scale containers warm from the moment its cron expression fires (in the organization\'s time zone) until the next row fires; while it is on it replaces min_scale, max_scale still applies. At most 10 rows. '.$note,
            'maxItems' => 10,
            'items' => Schema::object([
                'cron' => Schema::string('Five-field cron expression (minute hour day-of-month month day-of-week), e.g. "0 9 * * 1-5".', ['minLength' => 9, 'maxLength' => 100]),
                'min_scale' => Schema::integer('Containers kept warm from this moment on (at most max_scale).', 0, 10),
            ], ['cron', 'min_scale']),
        ];
    }

    /**
     * The rows of a scale_schedule argument as the API takes them; null and [] (the warmer off)
     * give [].
     *
     * @return list<array{cron: string, min_scale: int}>
     */
    public static function scaleScheduleRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(fn (array $row) => [
            'cron' => trim((string) ($row['cron'] ?? '')),
            'min_scale' => (int) ($row['min_scale'] ?? 0),
        ], array_filter($rows, 'is_array')));
    }
}
