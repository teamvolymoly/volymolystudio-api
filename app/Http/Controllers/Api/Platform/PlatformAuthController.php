<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PlatformLoginRequest;
use App\Jobs\SendPlatformAdminLoginCode;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminLoginCode;
use App\Services\PlatformAuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PlatformAuthController extends Controller
{
    private const PENDING_LOGIN = 'platform_admin_pending_login';

    private const SESSION_VERSION = 'platform_admin_auth_session_version';

    public function __construct(private readonly PlatformAuditLogger $audit) {}

    public function csrfToken(Request $request): JsonResponse
    {
        return response()->json(['token' => $request->session()->token()]);
    }

    public function login(PlatformLoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $pending = $request->session()->get(self::PENDING_LOGIN);
        $guard = Auth::guard('platform');

        // Validate the password without creating an authenticated session.
        if (! $guard->validate([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'status' => PlatformAdmin::STATUS_ACTIVE,
        ])) {
            $request->session()->forget(self::PENDING_LOGIN);
            $knownAdmin = PlatformAdmin::where('email', $credentials['email'])->first();
            $this->audit->record($knownAdmin, $request, 'auth.login_failed', metadata: [
                'reason' => $knownAdmin && $knownAdmin->status !== PlatformAdmin::STATUS_ACTIVE
                    ? 'account_unavailable'
                    : 'invalid_credentials',
                'email_fingerprint' => hash('sha256', $credentials['email']),
            ]);

            return response()->json(['message' => 'Incorrect email or password.'], 401);
        }

        $candidate = $guard->getLastAttempted();
        if (! $candidate instanceof PlatformAdmin) {
            return response()->json(['message' => 'Incorrect email or password.'], 401);
        }

        return DB::transaction(function () use ($request, $guard, $candidate, $credentials, $pending): JsonResponse {
            $admin = PlatformAdmin::whereKey($candidate->id)->lockForUpdate()->first();
            if (! $admin || $admin->status !== PlatformAdmin::STATUS_ACTIVE
                || $admin->email !== $candidate->email || $admin->password !== $candidate->password) {
                return $this->expired($request);
            }

            $recent = PlatformAdminLoginCode::where('platform_admin_id', $admin->id)
                ->whereNull('consumed_at')
                ->where('last_sent_at', '>', now()->subMinute())
                ->latest('last_sent_at')
                ->first();

            if ($recent) {
                if ($this->pendingMatches($pending, $admin, $recent)) {
                    return $this->challengeResponse($admin, $recent, 'Use the verification code already sent to your email.');
                }

                return $this->cooldownResponse($recent);
            }

            $guard->getProvider()->rehashPasswordIfRequired($admin, $credentials);
            PlatformAdminLoginCode::where('platform_admin_id', $admin->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $code = $this->generateCode();
            $verification = PlatformAdminLoginCode::create([
                'platform_admin_id' => $admin->id,
                'email' => $admin->email,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'max_attempts' => 5,
                'expires_at' => now()->addMinutes(10),
                'last_sent_at' => now(),
            ]);

            if ($guard->check()) {
                $guard->logout();
            }
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->put(self::PENDING_LOGIN, [
                'admin_id' => $admin->id,
                'verification_id' => $verification->id,
                'password_fingerprint' => hash('sha256', $admin->password),
            ]);

            $this->audit->record($admin, $request, 'auth.login_challenge_created');
            $this->sendCode($verification, $code);

            return $this->challengeResponse($admin, $verification, 'Enter the verification code sent to your email.');
        }, 3);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        return $this->withPending($request, function (PlatformAdmin $admin, PlatformAdminLoginCode $verification) use ($request, $data): JsonResponse {
            if (! Hash::check($data['code'], $verification->code_hash)) {
                $verification->increment('attempts');
                $verification->refresh();
                $remaining = max(0, $verification->max_attempts - $verification->attempts);
                $this->audit->record($admin, $request, 'auth.login_verification_failed', metadata: [
                    'remaining_attempts' => $remaining,
                ]);

                if ($remaining === 0) {
                    $verification->update(['consumed_at' => now()]);
                    $request->session()->forget(self::PENDING_LOGIN);

                    return response()->json([
                        'message' => 'Too many incorrect codes. Please sign in again.',
                        'restart_login' => true,
                    ], 429);
                }

                return response()->json([
                    'message' => 'The verification code is incorrect.',
                    'remaining_attempts' => $remaining,
                ], 422);
            }

            $verification->update(['consumed_at' => now()]);
            if (! $admin->email_verified_at) {
                $admin->forceFill(['email_verified_at' => now()])->saveQuietly();
            }

            $request->session()->forget(self::PENDING_LOGIN);
            Auth::guard('platform')->login($admin);
            $request->session()->put(self::SESSION_VERSION, (int) $admin->auth_session_version);
            $request->session()->regenerate();
            $admin->forceFill(['last_login_at' => now()])->saveQuietly();
            $this->audit->record($admin, $request, 'auth.login_succeeded', metadata: [
                'verification_method' => 'email_code',
            ]);

            return response()->json([
                'message' => 'Logged in successfully.',
                'admin' => $this->adminPayload($admin),
            ]);
        });
    }

    public function resend(Request $request): JsonResponse
    {
        return $this->withPending($request, function (PlatformAdmin $admin, PlatformAdminLoginCode $verification) use ($request): JsonResponse {
            if ($verification->last_sent_at->gt(now()->subMinute())) {
                return $this->cooldownResponse($verification);
            }

            do {
                $code = $this->generateCode();
            } while (Hash::check($code, $verification->code_hash));

            $verification->update([
                'code_hash' => Hash::make($code),
                'last_sent_at' => now(),
            ]);
            $this->audit->record($admin, $request, 'auth.login_verification_resent');
            $this->sendCode($verification, $code);

            return response()->json([
                'message' => 'A new verification code has been sent to your email.',
                'retry_after' => 60,
            ], 202);
        });
    }

    public function me(Request $request): JsonResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        return response()->json(['admin' => $this->adminPayload($admin)]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var PlatformAdmin|null $admin */
        $admin = $request->user('platform');
        if ($admin) {
            $this->audit->record($admin, $request, 'auth.logout');
        }

        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    private function withPending(Request $request, Closure $action): JsonResponse
    {
        $pending = $request->session()->get(self::PENDING_LOGIN);
        if (! is_array($pending)) {
            return $this->expired($request);
        }

        return DB::transaction(function () use ($request, $pending, $action): JsonResponse {
            $admin = PlatformAdmin::whereKey($pending['admin_id'] ?? null)->lockForUpdate()->first();
            $verification = PlatformAdminLoginCode::whereKey($pending['verification_id'] ?? null)
                ->where('platform_admin_id', $pending['admin_id'] ?? null)
                ->lockForUpdate()
                ->first();

            if (! $admin || ! $verification || $admin->status !== PlatformAdmin::STATUS_ACTIVE
                || $verification->consumed_at || $verification->expires_at->lte(now())
                || $verification->attempts >= $verification->max_attempts
                || $verification->email !== $admin->email || ! $admin->password
                || ! is_string($pending['password_fingerprint'] ?? null)
                || ! hash_equals($pending['password_fingerprint'], hash('sha256', $admin->password))) {
                return $this->expired($request);
            }

            return $action($admin, $verification);
        }, 3);
    }

    private function challengeResponse(PlatformAdmin $admin, PlatformAdminLoginCode $verification, string $message): JsonResponse
    {
        $retryAfter = max(0, 60 - (now()->timestamp - $verification->last_sent_at->timestamp));

        return response()->json([
            'message' => $message,
            'verification_required' => true,
            'email' => $this->maskEmail($admin->email),
            'expires_in' => max(1, $verification->expires_at->timestamp - now()->timestamp),
            'retry_after' => $retryAfter,
        ], 202);
    }

    private function cooldownResponse(PlatformAdminLoginCode $verification): JsonResponse
    {
        $seconds = max(1, 60 - (now()->timestamp - $verification->last_sent_at->timestamp));

        return response()->json([
            'message' => "Please wait {$seconds} seconds before requesting another code.",
            'retry_after' => $seconds,
        ], 429)->header('Retry-After', (string) $seconds);
    }

    private function expired(Request $request): JsonResponse
    {
        $request->session()->forget(self::PENDING_LOGIN);

        return response()->json([
            'message' => 'Your login verification expired. Please sign in again.',
            'restart_login' => true,
        ], 422);
    }

    private function pendingMatches(mixed $pending, PlatformAdmin $admin, PlatformAdminLoginCode $verification): bool
    {
        return is_array($pending)
            && (int) ($pending['admin_id'] ?? 0) === $admin->id
            && (int) ($pending['verification_id'] ?? 0) === $verification->id
            && is_string($pending['password_fingerprint'] ?? null)
            && hash_equals($pending['password_fingerprint'], hash('sha256', $admin->password));
    }

    private function generateCode(): string
    {
        $testCode = (string) config('auth.platform_test_login_code', '');
        if (! app()->environment('production') && preg_match('/^\d{6}$/', $testCode) === 1) {
            return $testCode;
        }

        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function sendCode(PlatformAdminLoginCode $verification, string $code): void
    {
        SendPlatformAdminLoginCode::dispatch(
            $verification->id,
            $verification->code_hash,
            $code,
        )->afterCommit();
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('*', max(3, mb_strlen($local) - 1)).'@'.$domain;
    }

    /** @return array<string, bool|int|string|null> */
    private function adminPayload(PlatformAdmin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'status' => $admin->status,
            'must_change_password' => $admin->must_change_password,
            'last_login_at' => $admin->last_login_at?->toISOString(),
        ];
    }
}
