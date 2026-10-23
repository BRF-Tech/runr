<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Triggers\TriggerTypes;

/**
 * Shared by the trigger tools (docs/triggers.md): the trigger list of a project and the shape of
 * one trigger in a tool result. Everything goes through the trigger routes, so the API decides
 * what the caller may do; the API presents secrets by name only (TriggerPresenter), never their
 * values, and the tools pass that on.
 */
trait ReadsTriggers
{
    /** Trigger types when the registry is not booted (TriggerServiceProvider registers them). */
    private const KNOWN_TRIGGER_TYPES = ['http', 'schedule', 'queue', 'workflow', 'webhook', 'topic', 'platform_event'];

    /** GET /projects/{id}/triggers: ['triggers' => list, 'plan' => array]. */
    protected function triggerList(ToolContext $context, string $projectId): array
    {
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'triggers'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the triggers');
        }

        return [
            'triggers' => array_values(array_filter((array) $result->get('data', []), 'is_array')),
            'plan' => (array) $result->get('plan', []),
        ];
    }

    /**
     * One trigger of the project by id or name.
     *
     * @throws ToolError
     */
    protected function findTrigger(ToolContext $context, string $projectId, string $reference): array
    {
        $list = $this->triggerList($context, $projectId)['triggers'];
        foreach ($list as $trigger) {
            if (($trigger['id'] ?? null) === $reference || ($trigger['name'] ?? null) === $reference) {
                return $trigger;
            }
        }

        $names = implode(', ', array_map(fn (array $t) => (string) ($t['name'] ?? ''), $list));
        throw new ToolError("No trigger \"{$reference}\" in this project. Its triggers: {$names}.");
    }

    /** A trigger as tools show it: the presented fields without empty ones. */
    protected static function triggerSummary(array $trigger): array
    {
        $out = array_intersect_key($trigger, array_flip([
            'id', 'name', 'type', 'enabled', 'primary', 'position', 'runs_on', 'config', 'secrets', 'url',
            'has_signing_secret', 'delivery', 'retention_days', 'effective_timezone', 'timezone_source',
        ]));
        if (array_key_exists('config', $out)) {
            $out['config'] = (object) (array) $out['config'];
        }

        return array_filter($out, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** The trigger types the platform has (the type argument's enum). */
    protected static function triggerTypes(): array
    {
        $keys = app()->bound(TriggerTypes::class) ? app(TriggerTypes::class)->keys() : [];

        return $keys !== [] ? $keys : self::KNOWN_TRIGGER_TYPES;
    }

    /** The trigger argument of the tools that address one trigger. */
    protected static function triggerArgument(): array
    {
        return Schema::string('The trigger: its name or id (triggers_list shows them).', ['minLength' => 1, 'maxLength' => 64]);
    }

    /** The config argument: free keys, validated by the API per type. */
    protected static function configArgument(string $description): array
    {
        return ['type' => 'object', 'description' => $description];
    }

    /** Write-only secrets, name → value. */
    protected static function secretsArgument(string $description): array
    {
        return Schema::map(['type' => 'string', 'maxLength' => 512], $description) + ['maxProperties' => 10];
    }

    /** The settings of each trigger type, for descriptions. */
    protected static function configHelp(): string
    {
        return 'Config per type — schedule: cron (5 fields or @hourly/@daily…), timezone (an IANA zone such as Europe/Berlin; omitted: the organization\'s time zone, which the schedule then follows); queue: stream (Redis stream), start_from earliest|latest; '
            .'webhook: verify none|stripe|github|slack|hmac, hmac {header, algorithm sha256|sha1|sha512, encoding hex|base64, prefix}, '
            .'delivery_id_header, tolerance_seconds, max_body_kb, retention_days (1-30) — secret signing_secret (required unless verify none); '
            .'topic: topic (an existing topic in the project\'s region), start_from latest|earliest; '
            .'platform_event: events (deployment.finished, deployment.failed, alert.fired, alert.resolved, runtime.patch_available, cost.limit_reached, environment.promotion.pending_approval, environment.promotion.approved, environment.promotion.rejected, environment.promotion.deployed, environment.promotion.failed or "*"), projects (project ids), retention_days; '
            .'workflow: queue_stream (optional); http: none. Every type except http and workflow also takes delivery {concurrency 1-32, max_retries 0-20, retry_backoff_seconds 1-300, retry_backoff_max_seconds 1-3600}.';
    }
}
