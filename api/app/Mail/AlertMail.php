<?php

namespace App\Mail;

use App\Services\Observability\Alerts\AlertMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An alert of an e-mail alert channel (observability alert rules). */
class AlertMail extends Mailable
{
    use Queueable;

    public function __construct(public AlertMessage $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alert->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.alert');
    }
}
