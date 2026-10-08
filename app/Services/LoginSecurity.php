<?php

namespace App\Services;

use App\Jobs\SendNewDeviceAlert;
use App\Models\LoginActivity;
use App\Models\RecognizedLoginDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoginSecurity
{
    public function record(Request $request, User $user, string $method): void
    {
        if (! config('login_security.enabled')) {
            return;
        }
        // Never send a security email whose action points to an unfinished screen.
        if ($this->reviewBaseUrl() === null) {
            return;
        }
        $token = $request->attributes->get('login_browser_token');
        if (! is_string($token) || ! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return;
        }

        DB::transaction(function () use ($request, $user, $method, $token): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $device = RecognizedLoginDevice::firstOrCreate(
                ['user_id' => $user->id, 'token_hash' => hash('sha256', $token)],
                ['last_seen_at' => now()],
            );
            if (! $device->wasRecentlyCreated) {
                $device->update(['last_seen_at' => now()]);

                return;
            }
            $reviewToken = bin2hex(random_bytes(32));
            $activity = LoginActivity::create(array_merge(app(LoginClientContext::class)->read($request), [
                'user_id' => $user->id,
                'recognized_login_device_id' => $device->id,
                'email' => $user->email,
                'login_method' => $method,
                'review_token_hash' => hash('sha256', $reviewToken),
                'review_expires_at' => now()->addMinutes(config('login_security.review_minutes')),
            ]));
            SendNewDeviceAlert::dispatch($activity->id, $reviewToken)->delay(now()->addSeconds(5))->afterCommit();
        });
    }

    public function reviewBaseUrl(): ?string
    {
        $path = config('login_security.review_path');
        if (! is_string($path) || ! preg_match('~\A/[a-zA-Z0-9][a-zA-Z0-9/_-]*\z~', $path)) {
            return null;
        }

        return rtrim(config('auth.frontend_url'), '/').$path;
    }
}
