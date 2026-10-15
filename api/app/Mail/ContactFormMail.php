<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactFormMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $data) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->data['email'], $this->data['first_name'].' '.$this->data['last_name'])],
            subject: 'Contact Form: '.$this->data['first_name'].' '.$this->data['last_name'],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.contact-form');
    }
}
