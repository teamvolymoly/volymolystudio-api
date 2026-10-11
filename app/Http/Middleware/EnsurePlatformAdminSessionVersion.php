<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdminSessionVersion
{
    private const SESSION_VERSION = 'platform_admin_auth_session_version';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('platform');
        $admin = $guard->user();

        if ($admin && (int) $request->session()->get(self::SESSION_VERSION, -1) !== (int) $admin->auth_session_version) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
