<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MerchantInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $merchantName,
        public readonly string $email,
        public readonly string $setupUrl,
        public readonly string $frontendUrl,
        public readonly int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Set up your Volymoly merchant account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.merchant-invitation',
            text: 'emails.merchant-invitation-text',
        );
    }
}
