<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlatformAdminLoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly int $expiresInMinutes,
        public readonly string $platformFrontendUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Volymoly Platform Admin verification code');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.platform-admin-login-code',
            text: 'emails.platform-admin-login-code-text',
        );
    }
}
