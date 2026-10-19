<?php

namespace Modules\Billing\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mail of a billing notification (BillingNotifier): a title, a short text and a link into the
 * console.
 */
class BillingNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $notificationTitle,
        public string $notificationText,
        public ?string $actionUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notificationTitle);
    }

    public function content(): Content
    {
        /** @var view-string $view the module's view (BillingServiceProvider::loadViewsFrom) */
        $view = 'billing::emails.notification';

        return new Content(view: $view, with: [
            'title' => $this->notificationTitle,
            'text' => $this->notificationText,
            'actionUrl' => $this->actionUrl,
        ]);
    }
}
