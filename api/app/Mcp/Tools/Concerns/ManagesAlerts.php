<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\ApiResult;
use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/**
 * Alert channels and rules of the organization (docs/observability.md, "Alerting") for the alert
 * tools: argument schemas mirroring the API's validation, id lookups by id or name, and calls
 * through the routes as the caller. Channel secrets (webhook URLs, bot tokens, HMAC secrets)
 * go in, never come back — the API answers with a masked config.
 */
trait ManagesAlerts
{
    protected static array $channelTypes = ['email', 'slack', 'teams', 'telegram', 'webhook'];

    protected static array $ruleTypes = ['resource_threshold', 'uptime_down', 'error_rate', 'p95_latency', 'cold_start_rate'];

    protected static array $severities = ['info', 'warning', 'critical'];

    /** config of a channel: the keys of its type (validated by the API per type). */
    protected static function channelConfigSchema(): array
    {
        return Schema::object([
            'emails' => Schema::array(['type' => 'string', 'maxLength' => 254], 'email: 1–20 recipients.', 20),
            'webhook_url' => Schema::string('slack / teams: the incoming webhook URL (https).', ['maxLength' => 1000]),
            'bot_token' => Schema::string('telegram: the bot token (123456:ABC…).', ['maxLength' => 130]),
            'chat_id' => Schema::string('telegram: the chat id (-100… or @channel).', ['maxLength' => 70]),
            'url' => Schema::string('webhook: the URL the alert is POSTed to.', ['maxLength' => 1000]),
            'secret' => Schema::string('webhook: optional HMAC secret (16–200 characters) for the signature header.', ['maxLength' => 200]),
        ]);
    }

    /** params of a rule: the keys of its type (validated by the API per type). */
    protected static function ruleParamsSchema(): array
    {
        return Schema::object([
            'threshold' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => 'error_rate / cold_start_rate: percent.'],
            'threshold_ms' => ['type' => 'number', 'minimum' => 1, 'maximum' => 3600000, 'description' => 'p95_latency: milliseconds.'],
            'window_minutes' => Schema::integer('Metric rules: evaluation window.', 1, 1440),
            'min_requests' => Schema::integer('Metric rules: fewer requests in the window never fire.', 0, 1000000),
            'resource' => Schema::enum(['cpu', 'memory', 'any'], 'resource_threshold: which resource.'),
            'min_level' => Schema::enum(['warning', 'critical'], 'resource_threshold: lowest level that fires.'),
        ]);
    }

    /** One alert API call; a refusal becomes a tool error. @throws ToolError */
    protected function alertCall(ToolContext $context, string $method, string $uri, array $body = [], ?string $what = null): ApiResult
    {
        $result = $context->api()->request($method, $uri, $body);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, $what);
        }

        return $result;
    }

    /**
     * A channel id from an id or a name. @throws ToolError
     */
    protected function channelId(ToolContext $context, string $reference): string
    {
        return $this->findByIdOrName($context, '/observability/alert-channels', $reference, 'alert channel', 'alert_channels_list');
    }

    /** A rule id from an id or a name. @throws ToolError */
    protected function ruleId(ToolContext $context, string $reference): string
    {
        return $this->findByIdOrName($context, '/observability/alert-rules', $reference, 'alert rule', 'alert_rules_list');
    }

    /** @param list<string> $references @return list<string> */
    protected function channelIds(ToolContext $context, array $references): array
    {
        return array_values(array_unique(array_map(fn ($r) => $this->channelId($context, (string) $r), $references)));
    }

    private function findByIdOrName(ToolContext $context, string $uri, string $reference, string $what, string $listTool): string
    {
        $reference = trim($reference);
        if (preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $reference)) {
            return strtolower($reference);
        }

        $result = $this->alertCall($context, 'GET', $uri, [], "Listing {$what}s");
        foreach ((array) $result->data() as $row) {
            if (is_array($row) && mb_strtolower((string) ($row['name'] ?? '')) === mb_strtolower($reference)) {
                return (string) $row['id'];
            }
        }

        throw new ToolError("No {$what} \"{$reference}\". {$listTool} shows them.");
    }

    protected static function channelPath(string $id, string ...$segments): string
    {
        return InternalApi::path('observability', 'alert-channels', $id, ...$segments);
    }

    protected static function rulePath(string $id): string
    {
        return InternalApi::path('observability', 'alert-rules', $id);
    }
}
