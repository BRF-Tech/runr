<?php

namespace App\Services\Observability\Alerts\Channels;

use App\Models\Observability\AlertChannel;
use App\Services\Observability\Alerts\AlertMessage;
use Illuminate\Http\Client\ConnectionException;

/** Telegram Bot API sendMessage (bot token + chat id; HTML formatting). */
class TelegramChannel extends HttpChannel
{
    public function rules(): array
    {
        return [
            'config.bot_token' => ['required', 'string', 'regex:/^\d{3,20}:[A-Za-z0-9_-]{20,100}$/'],
            'config.chat_id' => ['required', 'string', 'regex:/^(-?\d{1,20}|@[A-Za-z0-9_]{4,64})$/'],
        ];
    }

    public function send(AlertChannel $channel, AlertMessage $message): DeliveryResult
    {
        $token = (string) ($channel->config['bot_token'] ?? '');
        $api = rtrim((string) config('observability.alerts.telegram_api', 'https://api.telegram.org'), '/');

        try {
            // The Bot API host is fixed by the platform configuration: no SSRF guard needed
            $response = $this->client()->post("{$api}/bot{$token}/sendMessage", self::payload($message, (string) ($channel->config['chat_id'] ?? '')));
        } catch (ConnectionException $e) {
            // the token is part of the URL: never in the stored error
            return DeliveryResult::failed(str_replace($token, '***', $e->getMessage()));
        }

        $result = DeliveryResult::fromResponse($response);

        return $result->error ? new DeliveryResult($result->ok, $result->code, str_replace($token, '***', $result->error), $result->retryable) : $result;
    }

    public static function payload(AlertMessage $m, string $chatId): array
    {
        $e = fn (string $s) => htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lines = ['<b>'.$e($m->title).'</b>'];
        if ($m->text !== '') {
            $lines[] = $e($m->text);
        }
        foreach ($m->fields as $label => $value) {
            $lines[] = '<b>'.$e((string) $label).':</b> '.$e((string) $value);
        }
        if ($m->url) {
            $lines[] = '<a href="'.htmlspecialchars($m->url, ENT_QUOTES, 'UTF-8').'">Open in RunR</a>';
        }

        return [
            'chat_id' => $chatId,
            'text' => mb_substr(implode("\n", $lines), 0, 4000),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
    }
}
