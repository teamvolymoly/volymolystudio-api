<?php

namespace App\Jobs;

use App\Mail\MerchantInvitationMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class SendMerchantInvitationMail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 25;

    public array $backoff = [10, 60];

    public function __construct(
        public readonly int $merchantId,
        public readonly string $email,
        public readonly string $token,
        public readonly string $setupUrl,
    ) {}

    public function handle(): void
    {
        $merchant = User::whereKey($this->merchantId)
            ->where('email', $this->email)
            ->where('status', User::STATUS_ACTIVE)
            ->first();

        if (! $merchant || $merchant->hasCompletedAccountSetup()
            || ! Password::broker('users')->tokenExists($merchant, $this->token)) {
            return;
        }

        Mail::to($this->email)->send(new MerchantInvitationMail(
            merchantName: $merchant->name,
            email: $this->email,
            setupUrl: $this->setupUrl,
            frontendUrl: config('auth.frontend_url'),
            expiresInMinutes: (int) config('auth.passwords.users.expire'),
        ));
    }
}
