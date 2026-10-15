<?php

namespace App\Models\Observability;

use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Where alerts go: e-mail, Slack (incoming webhook), Microsoft Teams (workflow webhook),
 * Telegram (bot token + chat id) or a generic webhook (HMAC-signed JSON). config holds the
 * secrets (encrypted at rest, never returned by the API — toApi() shows a masked summary).
 *
 * config per type:
 *  - email:    {emails: [..]}
 *  - slack:    {webhook_url}
 *  - teams:    {webhook_url}
 *  - telegram: {bot_token, chat_id}
 *  - webhook:  {url, secret}
 *
 * @property string $id
 * @property string $name
 * @property string $type
 * @property array $config
 * @property bool $is_enabled
 * @property Carbon|null $last_test_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_error
 * @property string|null $created_by
 */
class AlertChannel extends Model
{
    use HasUlids;

    public const TYPES = ['email', 'slack', 'teams', 'telegram', 'webhook'];

    protected $table = 'alert_channels';

    protected $fillable = ['name', 'type', 'config', 'is_enabled', 'last_test_at', 'last_test_ok', 'last_test_error', 'created_by'];

    protected $hidden = ['config'];

    protected $casts = [
        'config' => Encrypted::class.':array',
        'is_enabled' => 'boolean',
        'last_test_ok' => 'boolean',
        'last_test_at' => 'datetime',
    ];

    /** What the UI may show of the configuration: no URLs with tokens, no secrets. */
    public function maskedConfig(): array
    {
        $c = $this->config ?? [];

        return match ($this->type) {
            'email' => ['emails' => array_values((array) ($c['emails'] ?? []))],
            'slack', 'teams' => ['webhook_host' => self::host($c['webhook_url'] ?? null), 'webhook_set' => ! empty($c['webhook_url'])],
            'telegram' => ['chat_id' => (string) ($c['chat_id'] ?? ''), 'bot_token_set' => ! empty($c['bot_token'])],
            'webhook' => ['url_host' => self::host($c['url'] ?? null), 'url_set' => ! empty($c['url']), 'secret_set' => ! empty($c['secret'])],
            default => [],
        };
    }

    private static function host(?string $url): ?string
    {
        return $url ? (parse_url($url, PHP_URL_HOST) ?: null) : null;
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'config' => $this->maskedConfig(),
            'is_enabled' => $this->is_enabled,
            'last_test_at' => $this->last_test_at?->toIso8601String(),
            'last_test_ok' => $this->last_test_ok,
            'last_test_error' => $this->last_test_error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
