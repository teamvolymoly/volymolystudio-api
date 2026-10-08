<?php

namespace App\Providers;

use App\Services\LoginClientContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        $this->assertProductionAuthConfiguration();

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
            RateLimiter::for($name, static function (Request $request) use ($attempts, $name) {
                // Named limiters isolate endpoints. Only authenticated proxy metadata
                // may replace the transport IP; arbitrary forwarded headers cannot.
                $ip = app(LoginClientContext::class)->rateLimitIp($request);
                $requestLimits = [
                    Limit::perMinute($attempts)->by('ip:'.hash('sha256', $ip)),
                ];
                foreach (self::rateLimitIdentities($request, $name) as $identity) {
                    $requestLimits[] = Limit::perMinute($attempts)->by($identity);
                }

                return $requestLimits;
            });
        }
    }

    /**
     * Apply a second throttle bucket to credential/account identities so
     * rotating source IPs cannot bypass the endpoint-specific limits.
     *
     * @return list<string>
     */
    private static function rateLimitIdentities(Request $request, string $limiter): array
    {
        $fields = match ($limiter) {
            'auth-login', 'auth-password-forgot', 'auth-password-reset',
            'auth-verification-send', 'auth-verification-verify' => ['email'],
            'auth-account-recover' => ['new_email', 'account_email'],
            default => [],
        };
        $identities = [];
        foreach ($fields as $field) {
            $value = $request->input($field);
            if (! is_string($value)) {
                continue;
            }
            $normalized = Str::lower(trim($value));
            if ($normalized !== '') {
                $identities[] = 'identity:'.$limiter.':'.hash('sha256', $field.':'.$normalized);
            }
        }

        if (in_array($limiter, ['auth-login-verify', 'auth-login-resend'], true)) {
            $userId = $request->session()->get('login_otp.user_id');
            if (is_int($userId) || (is_string($userId) && ctype_digit($userId))) {
                $identities[] = 'identity:'.$limiter.':'.hash('sha256', 'user:'.$userId);
            }
        }

        return array_values(array_unique($identities));
    }

    private function assertProductionAuthConfiguration(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $errors = [];
        if (strlen((string) config('login_security.proxy_secret')) < 32) {
            $errors[] = 'AUTH_PROXY_SECRET must contain at least 32 characters.';
        }
        if (in_array((string) config('mail.default'), ['array', 'log'], true)) {
            $errors[] = 'MAIL_MAILER must deliver real authentication email.';
        }

        if ($errors !== []) {
            throw new \RuntimeException('Unsafe production authentication configuration: '.implode(' ', $errors));
        }
    }
}
