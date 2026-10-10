<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\LoginActivity;
use App\Models\RecognizedLoginDevice;
use App\Models\User;
use App\Services\LoginSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LoginActivitySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_an_activity_link_is_read_only(): void
    {
        [$user, $device, $activity, $token] = $this->activity();

        $response = $this->withHeader('X-Activity-Token', $token)
            ->getJson('/api/auth/security/activity');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertJsonPath('activity.email', $user->email)
            ->assertJsonPath('activity.device', 'Chrome 152 on Windows 10 or later')
            ->assertJsonPath('activity.secured', false)
            ->assertJsonPath('activity.secured_at', null);

        $this->assertNull($activity->fresh()->secured_at);
        $this->assertNull($device->fresh()->revoked_at);
        $this->assertSame(0, $user->fresh()->auth_session_version);
    }

    public function test_secure_action_revokes_access_and_is_idempotent(): void
    {
        config()->set('session.driver', 'database');

        [$user, $device, $activity, $token] = $this->activity([
            'auth_session_version' => 7,
            'remember_token' => 'known-remember-token',
        ]);
        $oldRememberToken = $user->remember_token;
        $user->createToken('existing-api-access');

        DB::table('sessions')->insert([
            'id' => 'authenticated-session',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.20',
            'user_agent' => 'Chrome/152.0',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $verification = EmailVerificationCode::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'purpose' => 'login',
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(10),
            'last_sent_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/security/secure', ['token' => $token]);

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson([
                'secured' => true,
                'already_secured' => false,
            ]);

        $user->refresh();
        $this->assertSame(8, $user->auth_session_version);
        $this->assertNotSame($oldRememberToken, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'authenticated-session']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNotNull($verification->fresh()->consumed_at);
        $this->assertNotNull($device->fresh()->revoked_at);
        $this->assertNotNull($activity->fresh()->secured_at);

        $this->postJson('/api/auth/security/secure', ['token' => $token])
            ->assertOk()
            ->assertJson([
                'secured' => true,
                'already_secured' => true,
            ]);

        $this->assertSame(8, $user->fresh()->auth_session_version);
    }

    public function test_invalid_or_expired_links_cannot_revoke_sessions(): void
    {
        [$user, , $activity, $token] = $this->activity();
        $activity->update(['review_expires_at' => now()->subMinute()]);

        $this->getJson('/api/auth/security/activity?token='.$token)->assertGone();
        $this->postJson('/api/auth/security/secure', ['token' => $token])->assertGone();
        $this->postJson('/api/auth/security/secure', ['token' => 'invalid'])->assertGone();

        $this->assertSame(0, $user->fresh()->auth_session_version);
        $this->assertNull($activity->fresh()->secured_at);
    }

    public function test_a_revoked_browser_is_recognized_again_on_its_next_login(): void
    {
        Queue::fake();
        config()->set('login_security.enabled', true);
        config()->set('login_security.review_path', '/security/activity');
        config()->set('auth.frontend_url', 'https://frontend.example');

        [$user, $device] = $this->activity();
        $device->update(['revoked_at' => now()]);
        $browserToken = str_repeat('b', 64);
        $device->update(['token_hash' => hash('sha256', $browserToken)]);

        $request = Request::create('/api/auth/login/verify', 'POST', server: [
            'HTTP_USER_AGENT' => 'Chrome/152.0 (Windows NT 10.0)',
        ]);
        $request->attributes->set('login_browser_token', $browserToken);

        app(LoginSecurity::class)->record($request, $user, 'password');

        $this->assertNull($device->fresh()->revoked_at);
        $this->assertDatabaseCount('login_activities', 2);
    }

    /**
     * @param  array<string, mixed>  $userAttributes
     * @return array{User, RecognizedLoginDevice, LoginActivity, string}
     */
    private function activity(array $userAttributes = []): array
    {
        $user = User::factory()->create($userAttributes);
        $device = RecognizedLoginDevice::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', str_repeat('a', 64)),
            'last_seen_at' => now(),
        ]);
        $token = bin2hex(random_bytes(32));
        $activity = LoginActivity::create([
            'user_id' => $user->id,
            'recognized_login_device_id' => $device->id,
            'email' => $user->email,
            'device' => 'Chrome 152 on Windows 10 or later',
            'ip_address' => '203.0.113.10',
            'location' => 'India',
            'login_method' => 'password',
            'review_token_hash' => hash('sha256', $token),
            'review_expires_at' => now()->addHour(),
        ]);

        return [$user, $device, $activity, $token];
    }
}
