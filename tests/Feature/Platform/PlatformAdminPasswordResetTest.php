<?php

namespace Tests\Feature\Platform;

use App\Jobs\SendPlatformAdminPasswordResetMail;
use App\Mail\PlatformAdminPasswordResetMail;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlatformAdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_admin_can_request_a_reset_link_without_account_enumeration(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $expected = [
            'message' => 'If an active administrator account exists for this email, a reset link will arrive shortly.',
            'retry_after' => 60,
        ];

        $this->postJson('/api/v1/platform/auth/password/forgot', ['email' => ' ADMIN@EXAMPLE.TEST '])
            ->assertAccepted()->assertExactJson($expected);
        Queue::assertPushed(SendPlatformAdminPasswordResetMail::class, 1);

        $this->postJson('/api/v1/platform/auth/password/forgot', ['email' => 'missing@example.test'])
            ->assertAccepted()->assertExactJson($expected);
        Queue::assertPushed(SendPlatformAdminPasswordResetMail::class, 1);

        $this->assertDatabaseHas('platform_admin_password_reset_tokens', ['email' => $admin->email]);
        $this->assertDatabaseMissing('platform_admin_password_reset_tokens', ['email' => 'missing@example.test']);
    }

    public function test_suspended_admin_gets_generic_response_without_a_reset_token(): void
    {
        Queue::fake();
        $admin = $this->admin(['status' => PlatformAdmin::STATUS_SUSPENDED]);

        $this->postJson('/api/v1/platform/auth/password/forgot', ['email' => $admin->email])
            ->assertAccepted();

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('platform_admin_password_reset_tokens', ['email' => $admin->email]);
    }

    public function test_reset_changes_password_consumes_token_and_revokes_existing_sessions(): void
    {
        $admin = $this->admin(['must_change_password' => true]);
        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0]);

        $token = Password::broker('platform_admins')->createToken($admin);
        $this->postJson('/api/v1/platform/auth/password/reset', [
            'email' => strtoupper($admin->email),
            'token' => $token,
            'password' => 'Updated!Password456',
            'password_confirmation' => 'Updated!Password456',
        ])->assertOk();

        $admin->refresh();
        $this->assertTrue(Hash::check('Updated!Password456', $admin->password));
        $this->assertSame(1, $admin->auth_session_version);
        $this->assertFalse($admin->must_change_password);
        $this->assertFalse(Password::broker('platform_admins')->tokenExists($admin, $token));

        Auth::forgetGuards();
        $this->getJson('/api/v1/platform/auth/me')->assertUnauthorized();
    }

    public function test_invalid_token_and_weak_password_cannot_change_password(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/v1/platform/auth/password/reset', [
            'email' => $admin->email,
            'token' => 'invalid-token',
            'password' => 'Updated!Password456',
            'password_confirmation' => 'Updated!Password456',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');

        $token = Password::broker('platform_admins')->createToken($admin);
        $this->postJson('/api/v1/platform/auth/password/reset', [
            'email' => $admin->email,
            'token' => $token,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('Secure!Password123', $admin->fresh()->password));
    }

    public function test_merchant_token_cannot_reset_admin_with_the_same_email(): void
    {
        $admin = $this->admin();
        $merchant = User::factory()->create([
            'email' => $admin->email,
            'password' => 'Merchant!Password123',
        ]);
        $merchantToken = Password::broker('users')->createToken($merchant);
        $adminToken = Password::broker('platform_admins')->createToken($admin);

        $this->postJson('/api/v1/platform/auth/password/reset', [
            'email' => $admin->email,
            'token' => $merchantToken,
            'password' => 'Updated!Password456',
            'password_confirmation' => 'Updated!Password456',
        ])->assertUnprocessable();

        $this->assertTrue(Hash::check('Secure!Password123', $admin->fresh()->password));
        $this->assertTrue(Password::broker('platform_admins')->tokenExists($admin, $adminToken));
        $this->assertTrue(Password::broker('users')->tokenExists($merchant, $merchantToken));
    }

    public function test_reset_url_targets_platform_frontend_and_keeps_token_in_fragment(): void
    {
        config(['auth.platform_frontend_url' => 'https://platform.example.test']);

        $this->assertSame(
            'https://platform.example.test/reset-password?email=admin%40example.test#token=secret-token',
            $this->admin()->passwordResetUrl('secret-token'),
        );
    }

    public function test_reset_job_sends_dedicated_email_only_while_token_is_valid(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $token = Password::broker('platform_admins')->createToken($admin);
        $resetUrl = $admin->passwordResetUrl($token);
        $job = new SendPlatformAdminPasswordResetMail($admin->email, $token, $resetUrl);

        $job->handle();
        Mail::assertSent(PlatformAdminPasswordResetMail::class, function (PlatformAdminPasswordResetMail $mail) use ($admin, $resetUrl): bool {
            return $mail->hasTo($admin->email)
                && $mail->resetUrl === $resetUrl
                && str_contains($mail->render(), 'Reset your admin password');
        });

        Password::broker('platform_admins')->deleteToken($admin);
        $job->handle();
        Mail::assertSent(PlatformAdminPasswordResetMail::class, 1);
    }

    private function admin(array $attributes = []): PlatformAdmin
    {
        return PlatformAdmin::create(array_merge([
            'name' => 'Platform Owner',
            'email' => 'admin@example.test',
            'password' => 'Secure!Password123',
            'status' => PlatformAdmin::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ], $attributes));
    }
}
