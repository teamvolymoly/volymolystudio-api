<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PlatformLoginRequest;
use App\Models\PlatformAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlatformAuthController extends Controller
{
    private const SESSION_VERSION = 'platform_admin_auth_session_version';

    public function csrfToken(Request $request): JsonResponse
    {
        return response()->json([
            'token' => $request->session()->token(),
        ]);
    }

    public function login(PlatformLoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $guard = Auth::guard('platform');

        if (! $guard->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'status' => PlatformAdmin::STATUS_ACTIVE,
        ])) {
            return response()->json([
                'message' => 'Incorrect email or password.',
            ], 401);
        }

        $admin = $guard->user();
        if (! $admin instanceof PlatformAdmin) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Unable to start the administrative session.',
            ], 500);
        }

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_VERSION, (int) $admin->auth_session_version);
        $admin->forceFill(['last_login_at' => now()])->saveQuietly();

        return response()->json([
            'message' => 'Logged in successfully.',
            'admin' => $this->adminPayload($admin),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        return response()->json([
            'admin' => $this->adminPayload($admin),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
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
