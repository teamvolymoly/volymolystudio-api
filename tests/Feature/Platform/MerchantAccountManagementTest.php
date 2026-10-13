<?php

namespace Tests\Feature\Platform;

use App\Jobs\SendMerchantInvitationMail;
use App\Mail\MerchantInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PlatformAdmin;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MerchantAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_create_a_pending_merchant_and_queue_a_secure_invitation(): void
    {
        Queue::fake();
        $admin = $this->admin();

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->postJson('/api/v1/platform/merchants', [
                'name' => ' New Merchant ',
                'email' => ' MERCHANT@EXAMPLE.TEST ',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Merchant account created and invitation queued.')
            ->assertJsonPath('merchant.name', 'New Merchant')
            ->assertJsonPath('merchant.email', 'merchant@example.test')
            ->assertJsonPath('merchant.status', User::STATUS_ACTIVE)
            ->assertJsonPath('merchant.setup_status', 'pending')
            ->assertJsonPath('merchant.organizations_count', 0)
            ->assertJsonPath('merchant.stores_count', 0);

        $merchant = User::where('email', 'merchant@example.test')->firstOrFail();
        $this->assertNull($merchant->password);
        $this->assertNull($merchant->email_verified_at);
        $this->assertDatabaseHas('platform_admin_audit_logs', [
            'platform_admin_id' => $admin->id,
            'merchant_user_id' => $merchant->id,
            'action' => 'merchant.created',
        ]);
        Queue::assertPushed(SendMerchantInvitationMail::class, function (SendMerchantInvitationMail $job) use ($merchant): bool {
            return $job->merchantId === $merchant->id
                && $job->email === $merchant->email
                && Password::broker('users')->tokenExists($merchant, $job->token)
                && str_contains($job->setupUrl, 'screen=reset-password');
        });
    }

    public function test_merchant_email_must_be_unique_without_case_sensitivity(): void
    {
        Queue::fake();
        $admin = $this->admin();
        User::factory()->create(['email' => 'Existing@Example.test']);

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->postJson('/api/v1/platform/merchants', [
                'name' => 'Duplicate Merchant',
                'email' => ' existing@example.test ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'A merchant account with this email already exists.');

        $this->assertDatabaseCount('users', 1);
        Queue::assertNothingPushed();
    }

    public function test_invited_merchant_can_set_a_password_and_complete_account_setup(): void
    {
        Queue::fake();
        $admin = $this->admin();

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->postJson('/api/v1/platform/merchants', [
                'name' => 'Invited Merchant',
                'email' => 'invited@example.test',
            ])->assertCreated();

        $merchant = User::where('email', 'invited@example.test')->firstOrFail();
        $token = null;
        Queue::assertPushed(SendMerchantInvitationMail::class, function (SendMerchantInvitationMail $job) use (&$token): bool {
            $token = $job->token;

            return true;
        });

        $this->postJson('/api/auth/password/reset', [
            'email' => strtoupper($merchant->email),
            'token' => $token,
            'password' => 'Merchant!Password123',
            'password_confirmation' => 'Merchant!Password123',
        ])->assertOk()
            ->assertJsonPath('message', 'Your password has been reset successfully.');

        $merchant->refresh();
        $this->assertTrue(Hash::check('Merchant!Password123', $merchant->password));
        $this->assertNotNull($merchant->email_verified_at);
        $this->assertTrue($merchant->hasCompletedAccountSetup());
        $this->assertFalse(Password::broker('users')->tokenExists($merchant, (string) $token));

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->getJson('/api/v1/platform/merchants?search=invited@example.test')
            ->assertOk()
            ->assertJsonPath('merchants.0.setup_status', 'complete');
    }

    public function test_platform_admin_can_replace_a_pending_merchants_invitation(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $merchant = User::create([
            'name' => 'Pending Merchant',
            'email' => 'pending@example.test',
            'password' => null,
            'status' => User::STATUS_ACTIVE,
        ]);
        $oldToken = Password::broker('users')->createToken($merchant);

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->postJson("/api/v1/platform/merchants/{$merchant->id}/invitation")
            ->assertAccepted()
            ->assertJsonPath('message', 'Merchant invitation queued.');

        $newToken = null;
        Queue::assertPushed(SendMerchantInvitationMail::class, function (SendMerchantInvitationMail $job) use (&$newToken, $merchant): bool {
            $newToken = $job->token;

            return $job->merchantId === $merchant->id;
        });
        $this->assertFalse(Password::broker('users')->tokenExists($merchant, $oldToken));
        $this->assertTrue(Password::broker('users')->tokenExists($merchant, (string) $newToken));
        $this->assertDatabaseHas('platform_admin_audit_logs', [
            'platform_admin_id' => $admin->id,
            'merchant_user_id' => $merchant->id,
            'action' => 'merchant.invitation_resent',
        ]);
    }

    public function test_invitation_job_sends_mail_only_for_an_active_pending_account_with_a_valid_token(): void
    {
        Mail::fake();
        $merchant = User::create([
            'name' => 'Pending Merchant',
            'email' => 'pending@example.test',
            'password' => null,
            'status' => User::STATUS_ACTIVE,
        ]);
        $token = Password::broker('users')->createToken($merchant);
        $setupUrl = $merchant->passwordResetUrl($token);
        $job = new SendMerchantInvitationMail($merchant->id, $merchant->email, $token, $setupUrl);

        $job->handle();
        Mail::assertSent(MerchantInvitationMail::class, function (MerchantInvitationMail $mail) use ($merchant, $setupUrl): bool {
            return $mail->hasTo($merchant->email)
                && $mail->setupUrl === $setupUrl
                && str_contains($mail->render(), 'Set account password');
        });

        $merchant->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $job->handle();
        Mail::assertSent(MerchantInvitationMail::class, 1);
    }

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

    public function test_platform_admin_can_assign_update_and_remove_organization_access(): void
    {
        $admin = $this->admin();
        $merchant = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Access Organization',
            'slug' => 'access-organization',
            'status' => Organization::STATUS_ACTIVE,
        ]);
        $stores = collect(['First Store', 'Second Store'])->map(fn (string $name, int $index) => Store::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'slug' => 'access-store-'.$index,
            'status' => Store::STATUS_ACTIVE,
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
        ]));

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->putJson("/api/v1/platform/merchants/{$merchant->id}/organizations/{$organization->id}", [
                'role' => OrganizationMembership::ROLE_OWNER,
            ])->assertOk()
            ->assertJsonPath('merchant.organizations.0.role', OrganizationMembership::ROLE_OWNER)
            ->assertJsonPath('merchant.organizations_count', 1)
            ->assertJsonPath('merchant.stores_count', 2);

        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $merchant->id,
            'role' => OrganizationMembership::ROLE_OWNER,
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);
        foreach ($stores as $store) {
            $this->assertDatabaseHas('store_memberships', [
                'store_id' => $store->id,
                'user_id' => $merchant->id,
                'role' => StoreMembership::ROLE_OWNER,
            ]);
        }

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->putJson("/api/v1/platform/merchants/{$merchant->id}/organizations/{$organization->id}", [
                'role' => OrganizationMembership::ROLE_ADMIN,
            ])->assertOk()
            ->assertJsonPath('merchant.organizations.0.role', OrganizationMembership::ROLE_ADMIN)
            ->assertJsonPath('merchant.stores_count', 0);

        $this->assertDatabaseMissing('store_memberships', [
            'user_id' => $merchant->id,
            'role' => StoreMembership::ROLE_OWNER,
        ]);

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->deleteJson("/api/v1/platform/merchants/{$merchant->id}/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('merchant.organizations_count', 0);

        $this->assertDatabaseMissing('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $merchant->id,
        ]);
        $this->assertSame(2, $admin->auditLogs()
            ->where('action', 'merchant.organization_access_updated')->count());
        $this->assertSame(1, $admin->auditLogs()
            ->where('action', 'merchant.organization_access_removed')->count());
    }

    public function test_platform_admin_can_assign_change_and_remove_store_role(): void
    {
        $admin = $this->admin();
        $merchant = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Store Access Organization',
            'slug' => 'store-access-organization',
            'status' => Organization::STATUS_ACTIVE,
        ]);
        $store = Store::create([
            'organization_id' => $organization->id,
            'name' => 'Managed Store',
            'slug' => 'managed-store',
            'status' => Store::STATUS_ACTIVE,
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
        ]);

        foreach ([StoreMembership::ROLE_MANAGER, StoreMembership::ROLE_STAFF] as $role) {
            $this->actingAs($admin, 'platform')
                ->withSession(['platform_admin_auth_session_version' => 0])
                ->putJson("/api/v1/platform/merchants/{$merchant->id}/stores/{$store->id}", [
                    'role' => $role,
                ])->assertOk()
                ->assertJsonPath('merchant.stores.0.id', $store->id)
                ->assertJsonPath('merchant.stores.0.role', $role)
                ->assertJsonPath('merchant.stores.0.organization.id', $organization->id);
        }

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->deleteJson("/api/v1/platform/merchants/{$merchant->id}/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('merchant.stores_count', 0);

        $this->assertDatabaseMissing('store_memberships', [
            'store_id' => $store->id,
            'user_id' => $merchant->id,
        ]);
        $this->assertSame(2, $admin->auditLogs()->where('action', 'merchant.store_access_updated')->count());
        $this->assertSame(1, $admin->auditLogs()->where('action', 'merchant.store_access_removed')->count());
    }

    public function test_membership_roles_are_allowlisted(): void
    {
        $admin = $this->admin();
        $merchant = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Validation Organization',
            'slug' => 'validation-organization',
            'status' => Organization::STATUS_ACTIVE,
        ]);
        $store = Store::create([
            'organization_id' => $organization->id,
            'name' => 'Validation Store',
            'slug' => 'validation-store',
            'status' => Store::STATUS_ACTIVE,
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->putJson("/api/v1/platform/merchants/{$merchant->id}/organizations/{$organization->id}", [
                'role' => 'super-admin',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('role');
        $this->actingAs($admin, 'platform')
            ->withSession(['platform_admin_auth_session_version' => 0])
            ->putJson("/api/v1/platform/merchants/{$merchant->id}/stores/{$store->id}", [
                'role' => 'super-admin',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_platform_admin_can_suspend_and_reactivate_a_merchant_with_audit_history(): void
    {
        $admin = $this->admin();
        $merchant = User::factory()->create(['auth_session_version' => 4]);
        $merchant->createToken('merchant-test');
        $resetToken = Password::broker('users')->createToken($merchant);

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
        $this->assertFalse(Password::broker('users')->tokenExists($merchant, $resetToken));
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
        Queue::fake();
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

        $this->postJson('/api/auth/password/forgot', [
            'email' => strtoupper($merchant->email),
        ])->assertAccepted()
            ->assertJsonPath('message', 'If an account exists for this email, a reset link will arrive shortly.');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $merchant->email]);
        Queue::assertNothingPushed();
    }

    public function test_guest_and_merchant_cannot_manage_merchant_accounts(): void
    {
        $merchant = User::factory()->create();

        $this->getJson('/api/v1/platform/merchants')->assertUnauthorized();
        $this->postJson('/api/v1/platform/merchants', [
            'name' => 'Unauthorized Merchant',
            'email' => 'unauthorized@example.test',
        ])->assertUnauthorized();
        $this->actingAs($merchant, 'web')
            ->getJson('/api/v1/platform/merchants')
            ->assertUnauthorized();
        $this->actingAs($merchant, 'web')
            ->postJson('/api/v1/platform/merchants', [
                'name' => 'Unauthorized Merchant',
                'email' => 'unauthorized@example.test',
            ])->assertUnauthorized();
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
