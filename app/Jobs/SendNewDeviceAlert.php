<?php

namespace App\Jobs;

use App\Models\LoginActivity;
use App\Services\LoginSecurity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendNewDeviceAlert implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 25;

    public array $backoff = [10, 60];

    public function __construct(private readonly int $activityId, private readonly string $reviewToken) {}

    public function handle(): void
    {
        $baseUrl = app(LoginSecurity::class)->reviewBaseUrl();
        if (! config('login_security.enabled') || $baseUrl === null) {
            return;
        }
        DB::transaction(function () use ($baseUrl): void {
            $activity = LoginActivity::whereKey($this->activityId)->lockForUpdate()->first();
            if (! $activity || $activity->alert_sent_at || $activity->review_expires_at->lte(now())
                || ! hash_equals($activity->review_token_hash, hash('sha256', $this->reviewToken))) {
                return;
            }
            Mail::send(['html' => 'emails.new-device', 'text' => 'emails.new-device-text'], [
                'email' => $activity->email,
                'device' => $activity->device,
                'location' => $activity->location ?? 'Unavailable',
                'ipAddress' => $activity->ip_address ?? 'Unavailable',
                'reviewUrl' => $baseUrl.'#token='.urlencode($this->reviewToken),
                'frontendUrl' => config('auth.frontend_url'),
            ], function ($message) use ($activity): void {
                $message->to($activity->email)->subject('New device signed in to your Volymoly account');
            });
            $activity->update(['alert_sent_at' => now()]);
        });
    }
}
