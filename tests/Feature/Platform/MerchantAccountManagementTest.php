<?php

namespace Tests\Feature\Platform;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PlatformAdmin;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_search_and_filter_merchant_accounts(): void
    {
        $admin = $this->admin();
        $merchant = User::factory()->create([
            'name' => 'Rahul Merchant',
            'email' => 'rahul@example.test',
        ]);
        User::factory()->create([
            'name' => 'Suspended Seller',
            'status' => User::STATUS_SUSPENDED,
            'suspended_at' => now(),
        ]);

        $organization = Organization::create([
            'name' => 'Rahul Fashion',
            'slug' => 'rahul-fashion',
            'status' => Organization::STATUS_ACTIVE,
        ]);
        OrganizationMembership::create([
            'organization_id' => $organization->id,
            'user_id' => $merchant->id,
            'role' => OrganizationMembership::ROLE_OWNER,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);
        $store = Store::create([
            'organization_id' => $organization->id,
            'name' => 'Main Store',
            'slug' => 'rahul-main-store',
            'status' => Store::STATUS_ACTIVE,
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
        ]);
        StoreMembership::create([
            'store_id' => $store->id,
            'user_id' => $merchant->id,
            'role' => StoreMembership::ROLE_OWNER,
            'status' => StoreMembership::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->getJson('/api/v1/platform/merchants?search=rahul&status=active')
            ->assertOk()
            ->assertJsonCount(1, 'merchants')
            ->assertJsonPath('merchants.0.id', $merchant->id)
            ->assertJsonPath('merchants.0.organizations_count', 1)
            ->assertJsonPath('merchants.0.stores_count', 1)
            ->assertJsonPath('merchants.0.organizations.0.name', 'Rahul Fashion')
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.active', 1)
            ->assertJsonPath('summary.suspended', 1)
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_platform_admin_can_suspend_and_reactivate_a_merchant_with_audit_history(): void
    {
        $admin = $this->admin();
        $merchant = User::factory()->create(['auth_session_version' => 4]);
        $merchant->createToken('merchant-test');

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->patchJson("/api/v1/platform/merchants/{$merchant->id}/status", [
                'status' => User::STATUS_SUSPENDED,
            ])
            ->assertOk()
            ->assertJsonPath('merchant.status', User::STATUS_SUSPENDED);

        $merchant->refresh();
        $this->assertSame(User::STATUS_SUSPENDED, $merchant->status);
        $this->assertSame(5, $merchant->auth_session_version);
        $this->assertSame($admin->id, $merchant->suspended_by_platform_admin_id);
        $this->assertNotNull($merchant->suspended_at);
        $this->assertCount(0, $merchant->tokens);
        $this->assertDatabaseHas('platform_admin_audit_logs', [
            'platform_admin_id' => $admin->id,
            'merchant_user_id' => $merchant->id,
            'action' => 'merchant.status_changed',
        ]);

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->patchJson("/api/v1/platform/merchants/{$merchant->id}/status", [
                'status' => User::STATUS_ACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('merchant.status', User::STATUS_ACTIVE);

        $merchant->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $merchant->status);
        $this->assertSame(6, $merchant->auth_session_version);
        $this->assertNull($merchant->suspended_by_platform_admin_id);
        $this->assertNull($merchant->suspended_at);
        $this->assertSame(2, $admin->auditLogs()->where('action', 'merchant.status_changed')->count());
    }

    public function test_suspended_merchant_cannot_login_or_use_an_existing_session(): void
    {
        $merchant = User::factory()->create([
            'email' => 'blocked@example.test',
            'password' => 'Password!123',
            'status' => User::STATUS_SUSPENDED,
            'suspended_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $merchant->email,
            'password' => 'Password!123',
        ])->assertForbidden()
            ->assertJsonPath('message', 'This merchant account is suspended.');

        $this->actingAs($merchant, 'web')
            ->withSession(['auth_session_version' => $merchant->auth_session_version])
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_guest_and_merchant_cannot_manage_merchant_accounts(): void
    {
        $merchant = User::factory()->create();

        $this->getJson('/api/v1/platform/merchants')->assertUnauthorized();
        $this->actingAs($merchant, 'web')
            ->getJson('/api/v1/platform/merchants')
            ->assertUnauthorized();
    }

    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::create([
            'name' => 'Platform Owner',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'Secure!Password123',
            'status' => PlatformAdmin::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ]);
    }
}
