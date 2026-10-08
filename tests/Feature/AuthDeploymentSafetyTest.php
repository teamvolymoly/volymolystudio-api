<?php

namespace Tests\Feature;

use App\Jobs\SendAuthMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthDeploymentSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_forgot_password_request_is_not_blocked_by_failed_logins(): void
    {
        Queue::fake();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'missing@example.test', 'password' => 'IncorrectPassword99!',
            ])->assertUnauthorized();
        }
        $response = $this->postJson('/api/auth/password/forgot', ['email' => 'fresh@example.test']);
        $response->assertAccepted();
    }

    public function test_password_reset_revokes_an_existing_database_session(): void
    {
        config(['session.driver' => 'database']);
        Queue::fake();
        $user = User::factory()->create(['email' => 'session-audit@example.test', 'password' => 'OriginalPassword99!']);
        $this->withCredentials()->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'OriginalPassword99!',
        ])->assertAccepted();
        $pendingId = session()->getId();
        $job = Queue::pushed(SendAuthMail::class)->last();
        $body = (new \ReflectionProperty($job, 'body'))->getValue($job);
        preg_match('/\b\d{6}\b/', $body, $matches);
        $this->withCookie(config('session.cookie'), $pendingId)
            ->postJson('/api/auth/login/verify', ['code' => $matches[0]])->assertOk();
        $oldId = session()->getId();
        $this->assertTrue(DB::table('sessions')->where('id', $oldId)->exists());
        $token = Password::broker()->createToken($user);
        session()->flush();
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), Str::random(40))
            ->postJson('/api/auth/password/reset', [
                'email' => $user->email, 'token' => $token,
                'password' => 'NewPassword99!', 'password_confirmation' => 'NewPassword99!',
            ])->assertOk();
        session()->flush();
        Auth::forgetGuards();
        $response = $this->withCookie(config('session.cookie'), $oldId)->getJson('/api/auth/me');
        $response->assertUnauthorized();
    }

    public function test_reset_revokes_legacy_and_non_database_sessions_and_allows_fresh_login(): void
    {
        Queue::fake();
        $user = User::factory()->create(['password' => 'OriginalPassword99!']);
        $sessionKey = Auth::guard('web')->getName();
        $this->withSession([$sessionKey => $user->id])->getJson('/api/auth/me')->assertOk();
        $oldSession = session()->all();
        $token = Password::broker()->createToken($user);
        $this->postJson('/api/auth/password/reset', [
            'email' => $user->email, 'token' => $token, 'password' => 'NewPassword99!',
            'password_confirmation' => 'NewPassword99!',
        ])->assertOk();
        $this->assertSame(1, $user->fresh()->auth_session_version);
        Auth::forgetGuards();
        $this->withSession($oldSession)->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'NewPassword99!'])->assertAccepted();
        $job = Queue::pushed(SendAuthMail::class)->last();
        $body = (new \ReflectionProperty($job, 'body'))->getValue($job);
        preg_match('/\b\d{6}\b/', $body, $matches);
        $this->postJson('/api/auth/login/verify', ['code' => $matches[0]])->assertOk()
            ->assertSessionHas('auth_session_version', 1)->assertJsonMissingPath('user.auth_session_version');
        Auth::forgetGuards();
        $this->getJson('/api/auth/me')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_signed_proxy_clients_have_independent_limits_and_forged_headers_cannot_bypass_them(): void
    {
        config(['login_security.proxy_secret' => str_repeat('s', 40)]);
        $endpoint = '/api/auth/login';
        $headers = function (string $ip) use ($endpoint): array {
            $payload = base64_encode(json_encode(['agent' => 'Test', 'ip' => $ip, 'timestamp' => now()->timestamp]));

            return ['x-auth-client-context' => $payload,
                'x-auth-client-signature' => hash_hmac('sha256', "POST\n".$endpoint."\n".$payload, str_repeat('s', 40))];
        };
        $data = ['email' => 'missing@example.test', 'password' => 'wrong'];
        for ($i = 0; $i < 10; $i++) {
            $this->withHeaders($headers('203.0.113.1'))->postJson($endpoint, $data)->assertUnauthorized();
        }
        $this->withHeaders($headers('203.0.113.2'))->postJson($endpoint, $data)
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->withHeaders($headers('203.0.113.2'))->postJson($endpoint, [
            'email' => 'different@example.test', 'password' => 'wrong',
        ])->assertUnauthorized();
        for ($i = 0; $i < 10; $i++) {
            $forged = $headers('198.51.100.'.($i + 1));
            $forged['x-auth-client-signature'] = str_repeat('0', 64);
            $this->withHeaders($forged)->postJson($endpoint, [
                'email' => 'forged-'.$i.'@example.test', 'password' => 'wrong',
            ])->assertUnauthorized();
        }
        $this->withHeader('X-Forwarded-For', '192.0.2.123')->postJson($endpoint, [
            'email' => 'forged-final@example.test', 'password' => 'wrong',
        ])->assertTooManyRequests();
    }
}
