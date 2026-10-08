<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class IdentifyLoginBrowser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('login_security.enabled')) {
            $name = config('login_security.cookie');
            $token = $request->cookie($name);
            if (! is_string($token) || ! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
                $token = bin2hex(random_bytes(32));
            }
            $request->attributes->set('login_browser_token', $token);
            // Laravel's web middleware encrypts this cookie; it is not an auth token.
            Cookie::queue(Cookie::make($name, $token, config('login_security.device_days') * 1440,
                '/', null, (bool) config('session.secure'), true, false, 'lax'));
        }

        return $next($request);
    }
}
