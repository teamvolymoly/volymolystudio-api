<?php

namespace App\Jobs;

use App\Mail\PlatformAdminLoginCodeMail;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminLoginCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendPlatformAdminLoginCode implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 25;

    public array $backoff = [10, 60];

    public function __construct(
        private readonly int $verificationId,
        private readonly string $codeHash,
        private readonly string $code,
    ) {}

    public function handle(): void
    {
        $verification = PlatformAdminLoginCode::with('admin')->find($this->verificationId);
        if (! $verification || ! $verification->admin
            || $verification->admin->status !== PlatformAdmin::STATUS_ACTIVE
            || $verification->consumed_at || $verification->expires_at->lte(now())
            || $verification->attempts >= $verification->max_attempts
            || ! hash_equals($verification->code_hash, $this->codeHash)) {
            return;
        }

        $latestId = PlatformAdminLoginCode::where('platform_admin_id', $verification->platform_admin_id)
            ->max('id');
        if ((int) $latestId !== $verification->id) {
            return;
        }

        $secondsRemaining = max(1, $verification->expires_at->timestamp - now()->timestamp);

        Mail::to($verification->email)->send(new PlatformAdminLoginCodeMail(
            code: $this->code,
            expiresInMinutes: (int) ceil($secondsRemaining / 60),
            platformFrontendUrl: (string) config('auth.platform_frontend_url'),
        ));
    }
}
