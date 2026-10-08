<?php

namespace Tests\Feature;

use App\Jobs\SendAuthMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LoginOtpTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create([
            'email' => 'login@example.test',
            'password' => 'OriginalPassword99!',
            'email_verified_at' => null,
        ]);
    }

    private function startLogin(): string
    {
        $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'OriginalPassword99!',
        ])->assertAccepted()->assertJsonPath('otp_required', true)
            ->assertJsonMissingPath('user')->assertJsonMissingPath('code')
            ->assertSessionHas('login_otp.user_id', $this->user->id);

        return $this->latestCode();
    }

    private function latestCode(): string
    {
        $job = Queue::pushed(SendAuthMail::class)->last();
        $body = (new \ReflectionProperty($job, 'body'))->getValue($job);
        $this->assertSame(1, preg_match('/\b\d{6}\b/', $body, $matches));

        return $matches[0];
    }

    public function test_password_alone_cannot_access_me_and_otp_completes_session_login(): void
    {
        $code = $this->startLogin();
        $sessionId = session()->getId();
        $this->assertGuest('web');
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->assertTrue(Hash::check($code, EmailVerificationCode::sole()->code_hash));
        $other = User::factory()->create();

        $this->postJson('/api/auth/login/verify', [
            'code' => $code, 'email' => $other->email, 'user_id' => $other->id,
        ])->assertOk()->assertJsonPath('user.id', $this->user->id)
            ->assertJsonMissingPath('user.password')->assertSessionMissing('login_otp');
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertAuthenticatedAs($this->user, 'web');
        $this->assertNotNull($this->user->fresh()->email_verified_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->getJson('/api/auth/me')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_wrong_password_or_google_only_account_cannot_request_login_code(): void
    {
        foreach (['wrong-password', ''] as $password) {
            $response = $this->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => $password]);
            $password === '' ? $response->assertUnprocessable() : $response->assertUnauthorized();
        }
        $this->user->forceFill(['password' => null])->save();
        $this->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => 'anything'])->assertUnauthorized();
        Queue::assertNothingPushed();
        $this->assertGuest('web');
    }

    public function test_malformed_email_is_rejected_before_login_or_mail_delivery(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'user@example..com',
            'password' => 'OriginalPassword99!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        Queue::assertNothingPushed();
        $this->assertGuest('web');
    }

    public function test_login_code_requires_the_browser_session_that_passed_password(): void
    {
        $code = $this->startLogin();
        session()->invalidate();
        Auth::forgetGuards();
        $this->postJson('/api/auth/login/verify', ['code' => $code, 'email' => $this->user->email])
            ->assertUnprocessable()->assertJsonPath('restart_login', true);
        $this->postJson('/api/auth/login/resend')->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_five_wrong_codes_lock_the_challenge_even_after_resend(): void
    {
        $code = $this->startLogin();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson('/api/auth/login/verify', ['code' => $wrong])->assertUnprocessable();
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/login/resend')->assertAccepted();
        $this->assertSame(4, EmailVerificationCode::sole()->attempts);
        $newCode = $this->latestCode();
        $wrong = $newCode === '000000' ? '111111' : '000000';
        $this->postJson('/api/auth/login/verify', ['code' => $wrong])->assertStatus(429)
            ->assertJsonPath('restart_login', true)->assertSessionMissing('login_otp');
        $this->postJson('/api/auth/login/verify', ['code' => $newCode])->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_resend_has_cooldown_replaces_code_and_preserves_original_expiry(): void
    {
        $oldCode = $this->startLogin();
        $expiresAt = EmailVerificationCode::sole()->expires_at;
        $this->postJson('/api/auth/login/resend')->assertStatus(429);
        Queue::assertPushed(SendAuthMail::class, 1);
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/login/resend', ['email' => 'attacker@example.test'])->assertAccepted();
        Queue::assertPushed(SendAuthMail::class, 2);
        $job = Queue::pushed(SendAuthMail::class)->last();
        $this->assertSame($this->user->email, (new \ReflectionProperty($job, 'email'))->getValue($job));
        $this->assertTrue($expiresAt->eq(EmailVerificationCode::sole()->expires_at));
        $this->postJson('/api/auth/login/verify', ['code' => $oldCode])->assertUnprocessable();
        $this->postJson('/api/auth/login/verify', ['code' => $this->latestCode()])->assertOk();
    }

    public function test_expired_challenge_cannot_be_verified_or_extended_by_resend(): void
    {
        $code = $this->startLogin();
        $this->travel(10)->minutes();
        $this->postJson('/api/auth/login/resend')->assertUnprocessable()->assertJsonPath('restart_login', true);
        $this->postJson('/api/auth/login/verify', ['code' => $code])->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_consumed_code_cannot_be_replayed_even_with_an_old_pending_session(): void
    {
        $code = $this->startLogin();
        $pending = session('login_otp');
        $this->postJson('/api/auth/login/verify', ['code' => $code])->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->withSession(['login_otp' => $pending])->postJson('/api/auth/login/verify', ['code' => $code])
            ->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_changed_password_invalidates_pending_login(): void
    {
        $code = $this->startLogin();
        $this->user->forceFill(['password' => 'ChangedPassword99!'])->save();
        $this->postJson('/api/auth/login/verify', ['code' => $code])->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_changed_email_invalidates_pending_login(): void
    {
        $code = $this->startLogin();
        $this->user->forceFill(['email' => 'changed@example.test'])->save();
        $this->postJson('/api/auth/login/verify', ['code' => $code])->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_new_login_invalidates_previous_browser_challenge(): void
    {
        $oldCode = $this->startLogin();
        $oldPending = session('login_otp');
        $this->travel(61)->seconds();
        $newCode = $this->startLogin();
        $newPending = session('login_otp');
        $this->withSession(['login_otp' => $oldPending])->postJson('/api/auth/login/verify', ['code' => $oldCode])
            ->assertUnprocessable();
        $this->withSession(['login_otp' => $newPending])->postJson('/api/auth/login/verify', ['code' => $newCode])
            ->assertOk();
    }

    public function test_registration_verification_cannot_be_used_to_login(): void
    {
        $this->postJson('/api/auth/verification/send', ['email' => $this->user->email])->assertAccepted();
        $registrationCode = $this->latestCode();
        $this->postJson('/api/auth/verification/verify', ['email' => $this->user->email, 'code' => $registrationCode])
            ->assertOk();
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/login/verify', ['code' => $registrationCode])->assertUnprocessable();
        $this->postJson('/api/auth/verification/send', ['email' => $this->user->email, 'purpose' => 'login'])
            ->assertUnprocessable();
    }

    public function test_login_and_otp_endpoints_require_csrf(): void
    {
        $this->app->instance('env', 'local');
        foreach (['login', 'login/verify', 'login/resend'] as $endpoint) {
            $this->postJson('/api/auth/'.$endpoint, [])->assertStatus(419);
        }
    }
}
