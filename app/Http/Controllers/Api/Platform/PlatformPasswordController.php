<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PlatformChangePasswordRequest;
use App\Http\Requests\Platform\PlatformForgotPasswordRequest;
use App\Http\Requests\Platform\PlatformResetPasswordRequest;
use App\Models\PlatformAdmin;
use App\Services\PlatformAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlatformPasswordController extends Controller
{
    private const SESSION_VERSION = 'platform_admin_auth_session_version';

    public function __construct(private readonly PlatformAuditLogger $audit) {}

    public function forgot(PlatformForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $key = 'platform-password-reset-email:'.hash('sha256', $email);

        if (! RateLimiter::attempt($key, 1, static fn () => true, 60)) {
            $seconds = max(1, RateLimiter::availableIn($key));

            return response()->json([
                'message' => "Please wait {$seconds} seconds before requesting another reset link.",
                'retry_after' => $seconds,
            ], 429)->header('Retry-After', (string) $seconds);
        }

        $admin = PlatformAdmin::where('email', $email)
            ->where('status', PlatformAdmin::STATUS_ACTIVE)
            ->first();

        if ($admin) {
            Password::broker('platform_admins')->sendResetLink(['email' => $email]);
        }

        return response()->json([
            'message' => 'If an active administrator account exists for this email, a reset link will arrive shortly.',
            'retry_after' => 60,
        ], 202);
    }

    public function reset(PlatformResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        $status = DB::transaction(function () use ($data): string {
            $admin = PlatformAdmin::where('email', $data['email'])->lockForUpdate()->first();
            if (! $admin || $admin->status !== PlatformAdmin::STATUS_ACTIVE) {
                return Password::INVALID_USER;
            }

            return Password::broker('platform_admins')->reset(
                $data,
                function (PlatformAdmin $platformAdmin, string $password): void {
                    $platformAdmin->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                        'auth_session_version' => (int) $platformAdmin->auth_session_version + 1,
                        'password_changed_at' => now(),
                        'must_change_password' => false,
                    ])->save();
                },
            );
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This password reset link is invalid or expired.',
                'errors' => ['token' => ['This password reset link is invalid or expired.']],
            ], 422);
        }

        return response()->json([
            'message' => 'Your Platform Super Admin password has been reset successfully.',
        ]);
    }

    public function change(PlatformChangePasswordRequest $request): JsonResponse
    {
        /** @var PlatformAdmin $authenticatedAdmin */
        $authenticatedAdmin = $request->user('platform');

        $admin = DB::transaction(function () use ($request, $authenticatedAdmin): PlatformAdmin {
            /** @var PlatformAdmin $admin */
            $admin = PlatformAdmin::whereKey($authenticatedAdmin->id)->lockForUpdate()->firstOrFail();

            if (! $admin->must_change_password) {
                abort(response()->json([
                    'message' => 'A password change is not required for this account.',
                ], 409));
            }

            $password = $request->validated('password');
            if (Hash::check($password, $admin->password)) {
                throw ValidationException::withMessages([
                    'password' => ['Choose a password that is different from your current password.'],
                ]);
            }

            $admin->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'auth_session_version' => (int) $admin->auth_session_version + 1,
                'password_changed_at' => now(),
                'must_change_password' => false,
            ])->save();

            $this->audit->record($admin, $request, 'auth.password_changed', metadata: [
                'reason' => 'required_change',
            ]);

            return $admin;
        }, 3);

        $request->session()->put(self::SESSION_VERSION, (int) $admin->auth_session_version);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Your password has been changed successfully.',
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'status' => $admin->status,
                'must_change_password' => false,
                'last_login_at' => $admin->last_login_at?->toISOString(),
            ],
        ]);
    }
}
