<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mail of a promotion between environments (PromotionNotifier, docs/environments.md#notifications):
 * one waits for the recipient's approval, or the recipient's own promotion was approved, rejected,
 * deployed or failed.
 */
class PromotionMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $noticeTitle,
        public string $noticeText,
        public ?string $actionUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->noticeTitle);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.promotion', with: [
            'title' => $this->noticeTitle,
            'text' => $this->noticeText,
            'actionUrl' => $this->actionUrl,
        ]);
    }
}
