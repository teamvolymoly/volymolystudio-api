<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlatformAdminPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $email,
        public readonly string $resetUrl,
        public readonly string $platformFrontendUrl,
        public readonly int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your Volymoly Platform Admin password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.platform-admin-password-reset',
            text: 'emails.platform-admin-password-reset-text',
        );
    }
}
