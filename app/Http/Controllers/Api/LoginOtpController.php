<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendAuthMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LoginOtpController extends Controller
{
    private const PENDING = 'login_otp';

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $request->session()->forget(self::PENDING);
        $guard = Auth::guard('web');

        // Validate the password without creating an authenticated session.
        if (! $guard->validate($credentials)) {
            return response()->json(['message' => 'Incorrect email or password.'], 401);
        }

        $candidate = $guard->getLastAttempted();

        return DB::transaction(function () use ($request, $guard, $candidate, $credentials): JsonResponse {
            $user = User::whereKey($candidate->id)->lockForUpdate()->first();
            if (! $user || $user->password !== $candidate->password || $user->email !== $candidate->email) {
                return $this->expired($request);
            }

            $recent = EmailVerificationCode::where('user_id', $user->id)
                ->where('purpose', 'login')->where('last_sent_at', '>', now()->subMinute())->latest('last_sent_at')->first();
            if ($recent) {
                return $this->cooldownResponse($recent);
            }

            $guard->getProvider()->rehashPasswordIfRequired($user, $credentials);
            EmailVerificationCode::where('user_id', $user->id)->where('purpose', 'login')
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);

            $code = $this->generateCode();
            $verification = EmailVerificationCode::create([
                'user_id' => $user->id,
                'email' => $user->email,
                'purpose' => 'login',
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'max_attempts' => 5,
                'expires_at' => now()->addMinutes(10),
                'last_sent_at' => now(),
            ]);

            // Switching accounts must also remain unauthenticated until OTP succeeds.
            if ($guard->check()) {
                $guard->logout();
            }
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->put(self::PENDING, [
                'user_id' => $user->id,
                'verification_id' => $verification->id,
                'password_fingerprint' => hash('sha256', $user->password),
            ]);
            $this->sendCode($verification, $code);

            return response()->json([
                'message' => 'Enter the verification code sent to your email.',
                'otp_required' => true,
                'email' => $user->email,
                'expires_in' => 600,
                'retry_after' => 60,
            ], 202);
        }, 3);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        return $this->withPending($request, function (User $user, EmailVerificationCode $verification) use ($request, $data): JsonResponse {
            if (! Hash::check($data['code'], $verification->code_hash)) {
                $verification->increment('attempts');
                if ($verification->attempts >= $verification->max_attempts) {
                    $verification->update(['consumed_at' => now()]);
                    $request->session()->forget(self::PENDING);

                    return response()->json([
                        'message' => 'Too many incorrect codes. Please sign in again.',
                        'restart_login' => true,
                    ], 429);
                }

                return response()->json(['message' => 'The verification code is incorrect.'], 422);
            }

            $verification->update(['consumed_at' => now()]);
            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $request->session()->forget(self::PENDING);
            Auth::guard('web')->login($user);
            $request->session()->put('auth_session_version', (int) $user->auth_session_version);
            $request->session()->regenerate();
            app(\App\Services\LoginSecurity::class)->record($request, $user, 'password');

            return response()->json(['message' => 'Logged in successfully.', 'user' => $user]);
        });
    }

    public function resend(Request $request): JsonResponse
    {
        return $this->withPending($request, function (User $user, EmailVerificationCode $verification): JsonResponse {
            if ($verification->last_sent_at->gt(now()->subMinute())) {
                return $this->cooldownResponse($verification);
            }

            // Keep the original expiry and attempt budget; replace only the code.
            do {
                $code = $this->generateCode();
            } while (Hash::check($code, $verification->code_hash));
            $verification->update(['code_hash' => Hash::make($code), 'last_sent_at' => now()]);
            $this->sendCode($verification, $code);

            return response()->json(['message' => 'A new verification code has been queued for your email.', 'retry_after' => 60], 202);
        });
    }

    private function withPending(Request $request, Closure $action): JsonResponse
    {
        $pending = $request->session()->get(self::PENDING);
        if (! is_array($pending)) {
            return $this->expired($request);
        }

        return DB::transaction(function () use ($request, $pending, $action): JsonResponse {
            // Match login's lock order; serialize attempts and single-use consumption.
            $user = User::whereKey($pending['user_id'])->lockForUpdate()->first();
            $verification = EmailVerificationCode::whereKey($pending['verification_id'])
                ->where('user_id', $pending['user_id'])->where('purpose', 'login')->lockForUpdate()->first();

            if (! $user || ! $verification || $verification->consumed_at
                || ! $verification->expires_at || $verification->expires_at->lte(now())
                || $verification->attempts >= $verification->max_attempts
                || $verification->email !== $user->email || ! $user->password
                || ! hash_equals($pending['password_fingerprint'], hash('sha256', $user->password))) {
                return $this->expired($request);
            }

            return $action($user, $verification);
        }, 3);
    }

    private function expired(Request $request): JsonResponse
    {
        $request->session()->forget(self::PENDING);

        return response()->json([
            'message' => 'Your login verification expired. Please sign in again.',
            'restart_login' => true,
        ], 422);
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function cooldownResponse(EmailVerificationCode $verification): JsonResponse
    {
        $seconds = max(1, 60 - (now()->timestamp - $verification->last_sent_at->timestamp));

        return response()->json([
            'message' => "Please wait {$seconds} seconds before requesting another code.",
            'retry_after' => $seconds,
        ], 429)->header('Retry-After', (string) $seconds);
    }

    private function sendCode(EmailVerificationCode $verification, string $code): void
    {
        SendAuthMail::dispatch(
            $verification->email,
            'Your Volymoly login code',
            "Your Volymoly login code is {$code}. Use the latest code in the browser where you entered your password. Your login request expires 10 minutes after you enter your password.",
            ['verification_id' => $verification->id, 'code_hash' => $verification->code_hash, 'code' => $code],
        )->afterCommit();
    }
}
