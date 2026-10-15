<?php

namespace Modules\Tenancy\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Tenancy\Models\TenantInvitation;

class TenantInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public TenantInvitation $invitation,
        public string $acceptUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You have been invited to :tenant', [
                'tenant' => $this->invitation->tenant->name,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'tenancy::emails.invitation');
    }
}
