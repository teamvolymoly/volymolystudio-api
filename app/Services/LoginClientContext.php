<?php

namespace App\Services;

use Illuminate\Http\Request;

class LoginClientContext
{
    public function read(Request $request): array
    {
        $context = $this->verifiedProxyContext($request);
        $agent = substr((string) ($context['agent'] ?? $request->userAgent()), 0, 512);
        // Proxied requests with no verified client IP must not report the proxy IP.
        $ip = $request->hasHeader('x-auth-client-context') ? ($context['ip'] ?? null) : $request->ip();
        $location = $context['location'] ?? null;

        return [
            'device' => $this->describeAgent($agent),
            'ip_address' => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'location' => is_string($location) && preg_match('/\A[\pL\pN .,()\x{0027}-]{1,100}\z/u', $location) ? $location : null,
        ];
    }

    public function rateLimitIp(Request $request): string
    {
        $ip = $this->verifiedProxyContext($request)['ip'] ?? null;
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)
            ? $ip : (string) $request->ip();
    }

    private function verifiedProxyContext(Request $request): ?array
    {
        $secret = (string) config('login_security.proxy_secret');
        $payload = $request->header('x-auth-client-context', '');
        $signature = $request->header('x-auth-client-signature', '');
        if (strlen($secret) < 32 || strlen($payload) > 4096 || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)) {
            return null;
        }
        $signed = $request->method()."\n".$request->getPathInfo()."\n".$payload;
        if (! hash_equals(hash_hmac('sha256', $signed, $secret), $signature)) {
            return null;
        }
        $context = json_decode(base64_decode($payload, true) ?: '', true);
        if (! is_array($context) || ! is_int($context['timestamp'] ?? null)
            || abs(now()->timestamp - $context['timestamp']) > 60
            || ! is_string($context['agent'] ?? null)) {
            return null;
        }

        return $context;
    }

    public function describeAgent(string $agent): string
    {
        $browser = 'Unknown browser';
        foreach (['Edg(?:e|A|iOS)?' => 'Edge', 'OPR' => 'Opera', '(?:Firefox|FxiOS)' => 'Firefox', '(?:Chrome|CriOS)' => 'Chrome', 'Version' => 'Safari'] as $pattern => $name) {
            if (preg_match('~'.$pattern.'/([0-9]+)~i', $agent, $match)) {
                $browser = $name.' '.$match[1];
                break;
            }
        }
        $os = match (true) {
            str_contains($agent, 'Android') => 'Android',
            (bool) preg_match('/iPhone|iPad|iPod/', $agent) => 'iOS',
            str_contains($agent, 'Windows NT 10.0') => 'Windows 10 or later',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown device',
        };

        return $browser.' on '.$os;
    }
}
