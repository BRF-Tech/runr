<?php

namespace App\Mail;

use App\Models\Notification;
use App\Services\ResourceThresholdService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The capacity alarm by mail (ResourceThresholdService): a region's CPU or memory reservation
 * crossed a level, or — withStopped — it would cross one with its stopped projects back; then the
 * mail says by how much, how much capacity to add and which stopped projects need it.
 */
class ResourceThresholdMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $level;

    public string $regionName;

    public string $typeLabel;

    public float $percentage;

    public int $threshold;

    public string $settingsUrl;

    /** The alarm on the region with its stopped projects back */
    public bool $withStopped;

    /** What to do about it (the notification's body) */
    public ?string $advice;

    /** @var list<array{name: string, amount: int}> the stopped projects named, the largest first */
    public array $projects;

    public string $unit;

    public function __construct(Notification $notification)
    {
        $data = $notification->data ?? [];

        $this->level = $notification->level;
        $this->regionName = $data['region_name'] ?? '';
        $this->typeLabel = ($data['resource_type'] ?? 'cpu') === 'cpu' ? 'CPU' : 'Memory';
        $this->percentage = $data['percentage'] ?? 0;
        $this->threshold = $data['threshold'] ?? 0;
        $this->withStopped = ($data['basis'] ?? null) === ResourceThresholdService::WITH_STOPPED;
        $this->advice = $this->withStopped ? $notification->body : null;
        $this->projects = array_values(array_map(fn (array $p) => ['name' => (string) ($p['name'] ?? ''), 'amount' => (int) ($p['amount'] ?? 0)], (array) ($data['projects'] ?? [])));
        $this->unit = ($data['unit'] ?? 'm') === 'MB' ? ' MB' : 'm';
        // the region's capacity: Region → Workers (Administration → Regions & settings)
        $this->settingsUrl = rtrim(config('app.frontend_url', 'http://localhost:8061'), '/')
            .($this->withStopped ? '/settings' : '/settings?tab=resource-usage');
    }

    public function envelope(): Envelope
    {
        $icon = $this->level === 'critical' ? '🔴' : '🟡';
        $suffix = $this->withStopped ? ' with stopped projects' : '';

        return new Envelope(
            subject: "{$icon} {$this->regionName}: {$this->typeLabel} {$this->percentage}%{$suffix}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.resource-threshold');
    }
}
