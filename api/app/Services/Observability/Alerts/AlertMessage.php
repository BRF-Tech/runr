<?php

namespace App\Services\Observability\Alerts;

use App\Models\Observability\AlertDelivery;
use App\Models\Observability\AlertEvent;

/**
 * What a channel sends: the same title, text, severity and fields for every channel type.
 */
final class AlertMessage
{
    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public readonly string $title,
        public readonly string $text,
        public readonly string $severity,
        public readonly string $status,
        public readonly array $fields = [],
        public readonly ?string $url = null,
        public readonly string $kind = AlertDelivery::KIND_ALERT,
        public readonly ?string $eventId = null,
        public readonly ?string $type = null,
        public readonly ?string $firedAt = null,
    ) {}

    public static function fromEvent(AlertEvent $event, string $kind): self
    {
        $resolved = $kind === AlertDelivery::KIND_RESOLVE;
        $data = $event->data ?? [];

        $fields = [];
        foreach (['project' => 'Project', 'region' => 'Region', 'check' => 'Check', 'value' => 'Value', 'threshold' => 'Threshold', 'window' => 'Window'] as $key => $label) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $fields[$label] = (string) $data[$key];
            }
        }

        return new self(
            title: ($resolved ? '[RESOLVED] ' : '['.strtoupper($event->severity).'] ').$event->title,
            text: $resolved ? ($data['resolve_message'] ?? __('observability.alerts.resolved_text')) : (string) $event->message,
            severity: $event->severity,
            status: $resolved ? 'resolved' : 'firing',
            fields: $fields,
            url: $data['url'] ?? null,
            kind: $kind,
            eventId: $event->id,
            type: $event->type,
            firedAt: $event->fired_at?->toIso8601String(),
        );
    }

    public static function test(string $channelName): self
    {
        return new self(
            title: '[TEST] '.__('observability.alerts.test_title'),
            text: __('observability.alerts.test_text', ['channel' => $channelName]),
            severity: 'info',
            status: 'test',
            fields: ['Channel' => $channelName],
            url: rtrim((string) config('app.frontend_url', config('app.url')), '/').'/settings?tab=alerts',
            kind: AlertDelivery::KIND_TEST,
        );
    }

    /** The JSON body of the generic webhook. */
    public function toWebhookPayload(): array
    {
        return [
            'event' => $this->kind === AlertDelivery::KIND_TEST ? 'test' : ($this->status === 'resolved' ? 'alert.resolved' : 'alert.firing'),
            'id' => $this->eventId,
            'type' => $this->type,
            'title' => $this->title,
            'text' => $this->text,
            'severity' => $this->severity,
            'status' => $this->status,
            'fields' => (object) $this->fields,
            'url' => $this->url,
            'fired_at' => $this->firedAt,
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
