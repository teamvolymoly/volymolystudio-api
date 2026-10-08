<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SendAuthMail;
use App\Http\Controllers\Controller;
use App\Models\AccountRecoveryRequest;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function sendVerificationCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'purpose' => ['nullable', 'in:registration,account_recovery'],
            'request_id' => ['nullable', 'uuid'],
        ]);

        $purpose = $data['purpose'] ?? 'registration';
        $user = User::where('email', $data['email'])->first();

        if ($purpose === 'account_recovery' && ! empty($data['request_id'])) {
            $recovery = AccountRecoveryRequest::where('request_id', $data['request_id'])
                ->where('new_email', $data['email'])
                ->where('status', 'pending')
                ->first();

            if (! $recovery || ($recovery->expires_at && $recovery->expires_at->isPast())) {
                return response()->json([
                    'message' => 'This recovery request is invalid or expired.',
                ], 422);
            }
        }

        $verification = $this->issueVerificationCode($data['email'], $purpose, $user?->id);

        if (! $verification) {
            return response()->json([
                'message' => 'A verification code was sent recently. Please wait before requesting another code.',
            ], 429);
        }

        return response()->json([
            'message' => 'A verification code has been sent to your email.',
            'expires_in' => 600,
        ], 202);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'purpose' => ['nullable', 'in:registration,account_recovery'],
            'request_id' => ['nullable', 'uuid'],
        ]);

        $purpose = $data['purpose'] ?? 'registration';
        $verification = EmailVerificationCode::where('email', $data['email'])
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $verification || ($verification->expires_at && $verification->expires_at->isPast())) {
            return response()->json([
                'message' => 'This verification code is invalid or expired.',
                'errors' => ['code' => ['This verification code is invalid or expired.']],
            ], 422);
        }

        if ($verification->attempts >= $verification->max_attempts) {
            return response()->json([
                'message' => 'Too many verification attempts. Please request a new code.',
                'errors' => ['code' => ['Too many verification attempts.']],
            ], 429);
        }

        if (! Hash::check($data['code'], $verification->code_hash)) {
            $verification->increment('attempts');

            return response()->json([
                'message' => 'The verification code is incorrect.',
                'errors' => ['code' => ['The verification code is incorrect.']],
            ], 422);
        }

        $verification->forceFill(['consumed_at' => now()])->save();

        if ($purpose === 'registration' && $verification->user_id) {
            User::whereKey($verification->user_id)->update(['email_verified_at' => now()]);
        }

        if ($purpose === 'account_recovery' && ! empty($data['request_id'])) {
            AccountRecoveryRequest::where('request_id', $data['request_id'])
                ->where('new_email', $data['email'])
                ->where('status', 'pending')
                ->update([
                    'status' => 'verified',
                    'verified_at' => now(),
                ]);
        }

        return response()->json([
            'message' => 'Your verification code was accepted.',
            'verified' => true,
            'purpose' => $purpose,
            'request_id' => $data['request_id'] ?? null,
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Apply the same cooldown to known and unknown addresses.
        $key = 'password-reset-email:'.hash('sha256', Str::lower(trim($data['email'])));
        if (! RateLimiter::attempt($key, 1, static fn () => true, 60)) {
            $seconds = max(1, RateLimiter::availableIn($key));

            return response()->json([
                'message' => "Please wait {$seconds} seconds before requesting another reset link.",
                'retry_after' => $seconds,
            ], 429)->header('Retry-After', (string) $seconds);
        }

        Password::sendResetLink(['email' => $data['email']]);

        return response()->json([
            'message' => 'If an account exists for this email, a reset link will arrive shortly.',
            'retry_after' => 60,
        ], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:255',
                'confirmed',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (trim($value) !== $value) {
                        $fail('The password cannot begin or end with a space.');
                    }
                },
            ],
        ]);

        $status = DB::transaction(function () use ($data): string {
            // Serialize reset-token validation and consumption for this account.
            User::where('email', $data['email'])->lockForUpdate()->first();

            return Password::reset($data, function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'auth_session_version' => (int) $user->auth_session_version + 1,
                ])->save();

                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))
                        ->table(config('session.table', 'sessions'))
                        ->where('user_id', $user->id)->delete();
                }
                // Version checks also revoke other session drivers and any old
                // session recreated by a request that was already in flight.
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This password reset link is invalid or expired.',
                'errors' => ['token' => ['This password reset link is invalid or expired.']],
            ], 422);
        }

        return response()->json([
            'message' => 'Your password has been reset successfully.',
        ]);
    }

    public function startAccountRecovery(Request $request): JsonResponse
    {
        $data = $request->validate([
            'new_email' => ['required', 'email'],
            'account_email' => ['nullable', 'email'],
        ]);

        if (User::where('email', $data['new_email'])->exists()) {
            return response()->json([
                'message' => 'This email is already associated with another account.',
                'errors' => ['new_email' => ['This email is already associated with another account.']],
            ], 422);
        }

        $account = ! empty($data['account_email'])
            ? User::where('email', $data['account_email'])->first()
            : null;

        $recovery = AccountRecoveryRequest::create([
            'request_id' => (string) Str::uuid(),
            'user_id' => $account?->id,
            'account_email' => $data['account_email'] ?? null,
            'new_email' => $data['new_email'],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(30),
        ]);

        $verification = $this->issueVerificationCode($data['new_email'], 'account_recovery', $account?->id);

        if (! $verification) {
            $recovery->delete();

            return response()->json([
                'message' => 'A verification code was sent recently. Please wait before requesting another code.',
            ], 429);
        }

        return response()->json([
            'message' => 'A verification code has been sent to your new email.',
            'request_id' => $recovery->request_id,
            'expires_in' => 1800,
        ], 202);
    }

    private function issueVerificationCode(string $email, string $purpose, ?int $userId = null): ?EmailVerificationCode
    {
        $recent = EmailVerificationCode::where('email', $email)
            ->where('purpose', $purpose)
            ->where('created_at', '>=', now()->subSeconds(60))
            ->latest('id')
            ->first();

        if ($recent) {
            return null;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $verification = EmailVerificationCode::create([
            'user_id' => $userId,
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(10),
            'last_sent_at' => now(),
        ]);

        SendAuthMail::dispatch(
            $email,
            'Your Volymoly verification code',
            "Your Volymoly verification code is {$code}. It expires in 10 minutes.",
            ['verification_id' => $verification->id, 'code_hash' => $verification->code_hash],
        )->afterCommit();

        return $verification;
    }
}
