<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformAdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_platform_admin_can_login_and_read_own_profile(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/v1/platform/auth/login', [
            'email' => ' ADMIN@EXAMPLE.TEST ',
            'password' => 'Secure!Password123',
        ])->assertOk()
            ->assertJsonPath('admin.id', $admin->id)
            ->assertJsonPath('admin.email', 'admin@example.test')
            ->assertJsonMissingPath('admin.password');

        $this->assertAuthenticatedAs($admin, 'platform');
        $this->assertNotNull($admin->fresh()->last_login_at);

        $this->getJson('/api/v1/platform/auth/me')
            ->assertOk()
            ->assertJsonPath('admin.id', $admin->id);
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

        $this->postJson('/api/v1/platform/auth/login', [
            'email' => $admin->email,
            'password' => 'Secure!Password123',
        ])->assertOk();

        PlatformAdmin::whereKey($admin->id)->increment('auth_session_version');
        Auth::forgetGuards();

        $this->getJson('/api/v1/platform/auth/me')->assertUnauthorized();
        $this->assertGuest('platform');
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
