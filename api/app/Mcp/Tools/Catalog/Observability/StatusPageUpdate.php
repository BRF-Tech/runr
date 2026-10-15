<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class StatusPageUpdate extends Tool
{
    private const SETTINGS = ['slug', 'title', 'description', 'logo_url', 'custom_domain', 'is_public', 'show_incident_history', 'history_days'];

    public function name(): string
    {
        return 'status_page_update';
    }

    public function title(): string
    {
        return 'Change the status page';
    }

    public function description(): string
    {
        return 'Creates or changes the organization\'s public status page. Fields left out keep their current value (a new page needs slug and title; it starts not public). components lists uptime checks (uptime_checks_list) to show or hide, with an optional display name and order. Making the page public (is_public true) publishes the status of the shown checks to anyone — ask the user first.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'slug' => Schema::string('Path of the page: /status/<slug>.', ['minLength' => 3, 'maxLength' => 63, 'pattern' => '^[a-z0-9]([-a-z0-9]*[a-z0-9])?$']),
            'title' => Schema::string('Title of the page.', ['minLength' => 1, 'maxLength' => 120]),
            'description' => Schema::string('Text under the title ("" removes it).', ['maxLength' => 2000]),
            'logo_url' => Schema::string('https URL of a logo ("" removes it).', ['maxLength' => 500]),
            'custom_domain' => Schema::string('A custom domain for the page ("" removes it).', ['maxLength' => 253]),
            'is_public' => Schema::boolean('Publish the page.'),
            'show_incident_history' => Schema::boolean('Show past incidents.'),
            'history_days' => Schema::integer('Days of history shown.', 7, 90),
            'components' => Schema::array(Schema::object([
                'check' => Schema::string('Uptime check id.', ['minLength' => 26, 'maxLength' => 26]),
                'show' => Schema::boolean('Show it on the page.'),
                'name' => Schema::string('Name on the page.', ['maxLength' => 100]),
                'order' => Schema::integer('Position (smaller first).', 0, 10000),
            ], ['check', 'show']), 'Uptime checks to show or hide.', 200),
        ]);
    }

    public function permissions(): array
    {
        return ['status_page.manage'];
    }

    public function group(): string
    {
        return 'observability';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $current = $context->api()->get('/observability/status-page');
        if (! $current->ok()) {
            throw ToolError::fromApi($current, 'Reading the status page');
        }
        $page = (array) $current->get('data.page', []);

        $body = [];
        foreach (self::SETTINGS as $key) {
            $value = array_key_exists($key, $arguments) ? $arguments[$key] : ($page[$key] ?? null);
            if (in_array($key, ['description', 'logo_url', 'custom_domain'], true) && $value === '') {
                $value = null;
            }
            $body[$key] = $value;
        }
        $body['is_public'] = (bool) ($body['is_public'] ?? false);
        if (empty($body['slug']) || empty($body['title'])) {
            throw new ToolError('A new status page needs a slug and a title.');
        }
        $body = array_filter($body, fn ($v) => $v !== null);

        if (! empty($arguments['components'])) {
            $body['components'] = array_map(fn (array $c) => array_filter([
                'id' => (string) $c['check'],
                'show_on_status_page' => (bool) $c['show'],
                'status_page_name' => $c['name'] ?? null,
                'status_page_order' => $c['order'] ?? null,
            ], fn ($v) => $v !== null), array_values((array) $arguments['components']));
        }

        $result = $context->api()->put('/observability/status-page', $body);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The status page was not saved');
        }

        $data = (array) $result->data();
        unset($data['preview']);
        $context->setTarget('status_page', (string) ($data['page']['id'] ?? ''), (string) ($data['page']['title'] ?? ''));

        return ToolResult::ok($data, 'Status page saved at '.($data['public_path'] ?? '').(! empty($data['page']['is_public']) ? ' (public).' : ' (not public).'));
    }
}
