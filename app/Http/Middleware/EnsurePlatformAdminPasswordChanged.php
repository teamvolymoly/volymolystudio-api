<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdminPasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user('platform')?->must_change_password) {
            return response()->json([
                'message' => 'You must change your password before accessing platform administration.',
                'password_change_required' => true,
            ], 428);
        }

        return $next($request);
    }
}
