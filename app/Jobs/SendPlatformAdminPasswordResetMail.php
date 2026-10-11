<?php

namespace App\Jobs;

use App\Mail\PlatformAdminPasswordResetMail;
use App\Models\PlatformAdmin;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class SendPlatformAdminPasswordResetMail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 25;

    public array $backoff = [10, 60];

    public function __construct(
        private readonly string $email,
        private readonly string $token,
        private readonly string $resetUrl,
    ) {}

    public function handle(): void
    {
        $admin = PlatformAdmin::where('email', $this->email)
            ->where('status', PlatformAdmin::STATUS_ACTIVE)
            ->first();

        if (! $admin || ! Password::broker('platform_admins')->tokenExists($admin, $this->token)) {
            return;
        }

        Mail::to($this->email)->send(new PlatformAdminPasswordResetMail(
            email: $this->email,
            resetUrl: $this->resetUrl,
            platformFrontendUrl: config('auth.platform_frontend_url'),
            expiresInMinutes: (int) config('auth.passwords.platform_admins.expire'),
        ));
    }
}
