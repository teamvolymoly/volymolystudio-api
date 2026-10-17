<?php

namespace Tests\Feature\Platform;

use App\Jobs\SendPlatformAdminLoginCode;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminLoginCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlatformAdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_starts_verification_and_code_creates_the_session(): void
    {
        Queue::fake();
        $admin = $this->admin();

        $this->postJson('/api/v1/platform/auth/login', [
            'email' => ' ADMIN@EXAMPLE.TEST ',
            'password' => 'Secure!Password123',
        ])->assertAccepted()
            ->assertJsonPath('verification_required', true)
            ->assertJsonPath('email', 'a****@example.test');

        $this->assertGuest('platform');
        $this->assertNull($admin->fresh()->last_login_at);
        Queue::assertPushed(SendPlatformAdminLoginCode::class, 1);

        $verification = PlatformAdminLoginCode::where('platform_admin_id', $admin->id)->sole();
        $verification->update(['code_hash' => Hash::make('123456')]);

        $this->postJson('/api/v1/platform/auth/login/verify', ['code' => '123456'])
            ->assertOk()
            ->assertJsonPath('admin.id', $admin->id)
            ->assertJsonPath('admin.email', 'admin@example.test')
            ->assertJsonMissingPath('admin.password');

        $this->assertAuthenticatedAs($admin, 'platform');
        $this->assertNotNull($admin->fresh()->last_login_at);

        $this->getJson('/api/v1/platform/auth/me')
            ->assertOk()
            ->assertJsonPath('admin.id', $admin->id);

        $this->assertDatabaseHas('platform_admin_audit_logs', [
            'platform_admin_id' => $admin->id,
            'action' => 'auth.login_succeeded',
        ]);
    }

    public function test_wrong_credentials_and_suspended_admins_are_rejected_generically(): void
    {
        $this->admin();
        $suspended = $this->admin([
            'email' => 'suspended@example.test',
            'status' => PlatformAdmin::STATUS_SUSPENDED,
        ]);

        foreach ([
            ['email' => 'admin@example.test', 'password' => 'Wrong!Password123'],
            ['email' => $suspended->email, 'password' => 'Secure!Password123'],
            ['email' => 'missing@example.test', 'password' => 'Secure!Password123'],
        ] as $credentials) {
            $this->postJson('/api/v1/platform/auth/login', $credentials)
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Incorrect email or password.']);
        }
    }

    public function test_merchant_credentials_cannot_authenticate_as_a_platform_admin(): void
    {
        User::factory()->create([
            'email' => 'merchant@example.test',
            'password' => 'Secure!Password123',
        ]);

        $this->postJson('/api/v1/platform/auth/login', [
            'email' => 'merchant@example.test',
            'password' => 'Secure!Password123',
        ])->assertUnauthorized();

        $this->assertGuest('platform');
    }

    public function test_platform_session_cannot_access_merchant_profile(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_logout_invalidates_the_platform_session(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->postJson('/api/v1/platform/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertGuest('platform');
        $this->getJson('/api/v1/platform/auth/me')->assertUnauthorized();
    }

    public function test_session_version_change_revokes_an_existing_platform_session(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0]);

        PlatformAdmin::whereKey($admin->id)->increment('auth_session_version');
        Auth::forgetGuards();

        $this->getJson('/api/v1/platform/auth/me')->assertUnauthorized();
        $this->assertGuest('platform');
    }

    public function test_incorrect_codes_are_limited_and_never_create_a_session(): void
    {
        Queue::fake();
        $admin = $this->admin();

        $this->postJson('/api/v1/platform/auth/login', [
            'email' => $admin->email,
            'password' => 'Secure!Password123',
        ])->assertAccepted();
        PlatformAdminLoginCode::where('platform_admin_id', $admin->id)
            ->update(['code_hash' => Hash::make('123456')]);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/v1/platform/auth/login/verify', ['code' => '000000'])
                ->assertUnprocessable()
                ->assertJsonPath('remaining_attempts', 5 - $attempt);
        }

        $this->postJson('/api/v1/platform/auth/login/verify', ['code' => '000000'])
            ->assertTooManyRequests()
            ->assertJsonPath('restart_login', true);

        $this->assertGuest('platform');
        $this->postJson('/api/v1/platform/auth/login/verify', ['code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonPath('restart_login', true);
    }

    public function test_required_password_change_blocks_management_until_completed(): void
    {
        $admin = $this->admin(['must_change_password' => true]);

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->getJson('/api/v1/platform/tenancy')
            ->assertStatus(428)
            ->assertJsonPath('password_change_required', true);

        $this->postJson('/api/v1/platform/auth/password/change', [
            'password' => 'Updated!Password456',
            'password_confirmation' => 'Updated!Password456',
        ])->assertOk()->assertJsonPath('admin.must_change_password', false);

        $admin->refresh();
        $this->assertFalse($admin->must_change_password);
        $this->assertTrue(Hash::check('Updated!Password456', $admin->password));
        $this->assertSame(1, $admin->auth_session_version);

        $this->getJson('/api/v1/platform/tenancy')->assertOk();
        $this->assertDatabaseHas('platform_admin_audit_logs', [
            'platform_admin_id' => $admin->id,
            'action' => 'auth.password_changed',
        ]);
    }

    private function admin(array $attributes = []): PlatformAdmin
    {
        return PlatformAdmin::create(array_merge([
            'name' => 'Platform Owner',
            'email' => 'admin@example.test',
            'password' => Hash::make('Secure!Password123'),
            'status' => PlatformAdmin::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ], $attributes));
    }
}
