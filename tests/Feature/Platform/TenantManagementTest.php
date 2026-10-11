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

class TenantManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_creates_organization_and_assigns_existing_merchant_owner(): void
    {
        $admin = $this->admin();
        $merchant = User::factory()->create(['email' => 'owner@example.test']);

        $response = $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->postJson('/api/v1/platform/organizations', [
                'name' => ' Acme Retail ',
                'owner_email' => ' OWNER@EXAMPLE.TEST ',
            ])
            ->assertCreated()
            ->assertJsonPath('organization.name', 'Acme Retail')
            ->assertJsonPath('organization.slug', 'acme-retail')
            ->assertJsonPath('organization.owners.0.email', $merchant->email);

        $organizationId = $response->json('organization.id');
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organizationId,
            'user_id' => $merchant->id,
            'role' => OrganizationMembership::ROLE_OWNER,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('platform_admin_audit_logs', [
            'platform_admin_id' => $admin->id,
            'organization_id' => $organizationId,
            'action' => 'organization.created',
        ]);
    }

    public function test_store_creation_connects_all_organization_owners_and_supports_multiple_stores(): void
    {
        [$admin, $merchant, $organization] = $this->tenantFoundation();

        foreach (['Main Store', 'Main Store'] as $index => $name) {
            $this->actingAs($admin, 'platform')
                ->withSession(['platform_admin_auth_session_version' => 0])
                ->postJson('/api/v1/platform/stores', [
                    'organization_id' => $organization->id,
                    'name' => $name,
                    'currency' => 'inr',
                    'timezone' => 'Asia/Kolkata',
                ])->assertCreated()
                ->assertJsonPath('store.slug', $index === 0 ? 'main-store' : 'main-store-2')
                ->assertJsonPath('store.owners.0.email', $merchant->email);
        }

        $this->assertSame(2, StoreMembership::where('user_id', $merchant->id)
            ->where('role', StoreMembership::ROLE_OWNER)->count());
        $this->assertSame(2, Store::where('organization_id', $organization->id)->count());
        $this->assertSame(2, $merchant->fresh()->stores()->count());
        $this->assertSame(2, $admin->auditLogs()->where('action', 'store.created')->count());
    }

    public function test_platform_routes_reject_merchant_and_guest_sessions(): void
    {
        $merchant = User::factory()->create();

        $this->getJson('/api/v1/platform/tenancy')->assertUnauthorized();
        $this->actingAs($merchant, 'web')
            ->getJson('/api/v1/platform/tenancy')
            ->assertUnauthorized();
    }

    public function test_platform_admin_can_list_and_suspend_store_with_audit_log(): void
    {
        [$admin, , $organization] = $this->tenantFoundation();
        $store = $this->store($organization);

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->getJson('/api/v1/platform/tenancy')
            ->assertOk()
            ->assertJsonPath('summary.organizations', 1)
            ->assertJsonPath('summary.stores', 1)
            ->assertJsonPath('stores.0.id', $store->id);

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->patchJson("/api/v1/platform/stores/{$store->id}/status", [
                'status' => Store::STATUS_SUSPENDED,
            ])->assertOk()
            ->assertJsonPath('store.status', Store::STATUS_SUSPENDED);

        $this->assertDatabaseHas('platform_admin_audit_logs', [
            'store_id' => $store->id,
            'action' => 'store.status_changed',
        ]);
    }

    public function test_merchant_sees_only_assigned_stores_and_cannot_cross_tenant_boundary(): void
    {
        [, $merchant, $organization] = $this->tenantFoundation();
        $assigned = $this->store($organization, ['name' => 'Assigned', 'slug' => 'assigned']);
        StoreMembership::create([
            'store_id' => $assigned->id,
            'user_id' => $merchant->id,
            'role' => StoreMembership::ROLE_OWNER,
            'status' => StoreMembership::STATUS_ACTIVE,
        ]);

        $otherOrganization = Organization::create([
            'name' => 'Other Org',
            'slug' => 'other-org',
            'status' => Organization::STATUS_ACTIVE,
        ]);
        $otherStore = $this->store($otherOrganization, ['name' => 'Other', 'slug' => 'other']);

        $this->actingAs($merchant, 'web')
            ->getJson('/api/v1/merchant/stores')
            ->assertOk()
            ->assertJsonCount(1, 'stores')
            ->assertJsonPath('stores.0.id', $assigned->id)
            ->assertJsonPath('stores.0.role', StoreMembership::ROLE_OWNER);

        $this->actingAs($merchant, 'web')
            ->getJson("/api/v1/merchant/stores/{$assigned->id}")
            ->assertOk()
            ->assertJsonPath('store.role', StoreMembership::ROLE_OWNER);
        $this->actingAs($merchant, 'web')
            ->getJson("/api/v1/merchant/stores/{$otherStore->id}")
            ->assertForbidden();
    }

    public function test_suspended_store_remains_visible_but_cannot_be_opened_by_merchant(): void
    {
        [, $merchant, $organization] = $this->tenantFoundation();
        $store = $this->store($organization, ['status' => Store::STATUS_SUSPENDED]);
        StoreMembership::create([
            'store_id' => $store->id,
            'user_id' => $merchant->id,
            'role' => StoreMembership::ROLE_OWNER,
            'status' => StoreMembership::STATUS_ACTIVE,
        ]);

        $this->actingAs($merchant, 'web')
            ->getJson('/api/v1/merchant/stores')
            ->assertOk()
            ->assertJsonPath('stores.0.status', Store::STATUS_SUSPENDED);

        $this->actingAs($merchant, 'web')
            ->getJson("/api/v1/merchant/stores/{$store->id}")
            ->assertForbidden();
    }

    /** @return array{PlatformAdmin, User, Organization} */
    private function tenantFoundation(): array
    {
        $admin = $this->admin();
        $merchant = User::factory()->create(['email' => 'owner@example.test']);
        $organization = Organization::create([
            'name' => 'Acme Retail',
            'slug' => 'acme-retail',
            'status' => Organization::STATUS_ACTIVE,
            'created_by_platform_admin_id' => $admin->id,
        ]);
        OrganizationMembership::create([
            'organization_id' => $organization->id,
            'user_id' => $merchant->id,
            'role' => OrganizationMembership::ROLE_OWNER,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);

        return [$admin, $merchant, $organization];
    }

    private function store(Organization $organization, array $attributes = []): Store
    {
        return Store::create(array_merge([
            'organization_id' => $organization->id,
            'name' => 'Store',
            'slug' => 'store-'.fake()->unique()->numberBetween(1, 999999),
            'status' => Store::STATUS_ACTIVE,
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
        ], $attributes));
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
