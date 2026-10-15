<?php

namespace App\Mail;

use App\Services\WhiteLabel\BrandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Self-service signup: "confirm your e-mail address" with the verification link. Rendered with the
 * branded mail theme (resources/views/vendor/mail).
 */
class SignupVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $verifyUrl,
        public int $ttlHours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('whitelabel.mail.verify_subject', ['product' => app(BrandingService::class)->forMail()['product_name']]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.signup-verification');
    }
}
