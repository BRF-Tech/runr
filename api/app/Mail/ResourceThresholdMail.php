<?php

namespace App\Mail;

use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResourceThresholdMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $level;

    public string $regionName;

    public string $typeLabel;

    public float $percentage;

    public int $threshold;

    public string $settingsUrl;

    public function __construct(Notification $notification)
    {
        $data = $notification->data ?? [];

        $this->level = $notification->level;
        $this->regionName = $data['region_name'] ?? '';
        $this->typeLabel = ($data['resource_type'] ?? 'cpu') === 'cpu' ? 'CPU' : 'Memory';
        $this->percentage = $data['percentage'] ?? 0;
        $this->threshold = $data['threshold'] ?? 0;
        $this->settingsUrl = rtrim(config('app.frontend_url', 'http://localhost:8061'), '/')
            .'/settings?tab=resource-usage';
    }

    public function envelope(): Envelope
    {
        $icon = $this->level === 'critical' ? '🔴' : '🟡';

        return new Envelope(
            subject: "{$icon} {$this->regionName}: {$this->typeLabel} {$this->percentage}%",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.resource-threshold');
    }
}
