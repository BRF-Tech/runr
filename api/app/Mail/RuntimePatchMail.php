<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mail of a runtime patch notice (RuntimePatchNotifier): a critical or zero-day patch, a failed
 * automatic migration, an end-of-life reminder a week ahead, a refused patch feed.
 */
class RuntimePatchMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $noticeTitle,
        public string $noticeText,
        public ?string $actionUrl = null,
        public string $level = 'info',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->level === 'critical' ? '[CRITICAL] ' : '').$this->noticeTitle);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.runtime-patch', with: [
            'title' => $this->noticeTitle,
            'text' => $this->noticeText,
            'actionUrl' => $this->actionUrl,
        ]);
    }
}
