<?php

namespace App\Providers;

use App\Services\LoginClientContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $limits = [
            'auth-security-activity' => 20,
            'auth-google-redirect' => 20,
            'auth-google-callback' => 30,
            'auth-google-link-context' => 20,
            'auth-google-link' => 5,
            'auth-login' => 10,
            'auth-login-verify' => 12,
            'auth-login-resend' => 6,
            'auth-verification-send' => 6,
            'auth-verification-verify' => 12,
            'auth-password-forgot' => 5,
            'auth-password-reset' => 10,
            'auth-account-recover' => 5,
        ];
        foreach ($limits as $name => $attempts) {
            RateLimiter::for($name, static function (Request $request) use ($attempts) {
                // Named limiters isolate endpoints. Only authenticated proxy metadata
                // may replace the transport IP; arbitrary forwarded headers cannot.
                $ip = app(LoginClientContext::class)->rateLimitIp($request);
                return Limit::perMinute($attempts)->by(hash('sha256', $ip));
            });
        }
    }
}
