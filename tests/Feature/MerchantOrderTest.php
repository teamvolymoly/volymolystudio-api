<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class MerchantOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_lists_only_orders_from_an_assigned_store(): void
    {
        $merchant = User::factory()->create();
        $assignedStore = $this->store('assigned-store');
        $otherStore = $this->store('other-store');
        $this->assign($merchant, $assignedStore);

        $older = $this->order($assignedStore, 'ORD-1001', ['placed_at' => now()->subDay()]);
        $newer = $this->order($assignedStore, 'ORD-1002', [
            'status' => Order::STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_PAID,
            'total_amount' => 1499.50,
            'placed_at' => now(),
        ]);
        // Order numbers are tenant-local, so another store may reuse the same number.
        $this->order($otherStore, 'ORD-1001');

        $this->actingAs($merchant, 'web')
            ->getJson("/api/v1/merchant/stores/{$assignedStore->id}/orders")
            ->assertOk()
            ->assertJsonCount(2, 'orders')
            ->assertJsonPath('orders.0.id', $newer->id)
            ->assertJsonPath('orders.1.id', $older->id)
            ->assertJsonPath('summary.total_orders', 2)
            ->assertJsonPath('summary.completed_orders', 1)
            ->assertJsonPath('summary.paid_sales', '1499.50');
    }

    public function test_merchant_can_filter_orders_by_status_and_search_term(): void
    {
        $merchant = User::factory()->create();
        $store = $this->store('filter-store');
        $this->assign($merchant, $store);
        $this->order($store, 'ORD-ALPHA', [
            'status' => Order::STATUS_PROCESSING,
            'customer_name' => 'Asha Rao',
        ]);
        $this->order($store, 'ORD-BETA', [
            'status' => Order::STATUS_COMPLETED,
            'customer_name' => 'Ravi Shah',
        ]);

        $this->actingAs($merchant, 'web')
            ->getJson("/api/v1/merchant/stores/{$store->id}/orders?status=processing&query=asha")
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.order_number', 'ORD-ALPHA');
    }

    public function test_guest_and_unassigned_merchant_cannot_read_store_orders(): void
    {
        $store = $this->store('protected-store');
        $order = $this->order($store, 'ORD-PRIVATE');
        $otherMerchant = User::factory()->create();

        $this->getJson("/api/v1/merchant/stores/{$store->id}/orders")->assertUnauthorized();
        $this->actingAs($otherMerchant, 'web')
            ->getJson("/api/v1/merchant/stores/{$store->id}/orders")
            ->assertForbidden();
        $this->actingAs($otherMerchant, 'web')
            ->getJson("/api/v1/merchant/stores/{$store->id}/orders/{$order->id}")
            ->assertForbidden();
    }

    public function test_order_detail_must_belong_to_the_authorized_store(): void
    {
        $merchant = User::factory()->create();
        $assignedStore = $this->store('assigned-detail');
        $otherStore = $this->store('other-detail');
        $this->assign($merchant, $assignedStore);
        $otherOrder = $this->order($otherStore, 'ORD-CROSS-TENANT');

        $this->actingAs($merchant, 'web')
            ->getJson("/api/v1/merchant/stores/{$assignedStore->id}/orders/{$otherOrder->id}")
            ->assertNotFound();
    }

    public function test_every_supported_store_role_is_allowed_by_the_order_policy(): void
    {
        foreach (StoreMembership::roles() as $role) {
            $merchant = User::factory()->create();
            $store = $this->store("{$role}-store");
            $this->assign($merchant, $store, $role);

            $this->assertTrue(
                Gate::forUser($merchant)->allows('viewOrders', $store),
                "The {$role} role should be allowed to view orders.",
            );
        }
    }

    public function test_staff_merchant_can_view_orders_for_the_assigned_store(): void
    {
        $merchant = User::factory()->create();
        $store = $this->store('staff-assigned-store');
        $order = $this->order($store, 'ORD-STAFF');
        $this->assign($merchant, $store, StoreMembership::ROLE_STAFF);

        $this->actingAs($merchant, 'web')
            ->getJson("/api/v1/merchant/stores/{$store->id}/orders")
            ->assertOk()
            ->assertJsonPath('orders.0.id', $order->id);
    }

    public function test_unknown_store_role_does_not_grant_order_access(): void
    {
        $merchant = User::factory()->create();
        $store = $this->store('unknown-role-store');
        $this->assign($merchant, $store, 'billing-only');

        $this->actingAs($merchant, 'web')
            ->getJson("/api/v1/merchant/stores/{$store->id}/orders")
            ->assertForbidden();
    }

    public function test_manager_can_update_order_state_using_valid_forward_transitions(): void
    {
        $merchant = User::factory()->create();
        $store = $this->store('managed-store');
        $order = $this->order($store, 'ORD-MANAGED');
        $this->assign($merchant, $store, StoreMembership::ROLE_MANAGER);

        $this->actingAs($merchant, 'web')
            ->patchJson("/api/v1/merchant/stores/{$store->id}/orders/{$order->id}", [
                'status' => Order::STATUS_CONFIRMED,
                'payment_status' => Order::PAYMENT_PAID,
                'fulfillment_status' => Order::FULFILLMENT_PARTIAL,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Order updated successfully.')
            ->assertJsonPath('order.status', Order::STATUS_CONFIRMED)
            ->assertJsonPath('order.payment_status', Order::PAYMENT_PAID)
            ->assertJsonPath('order.fulfillment_status', Order::FULFILLMENT_PARTIAL)
            ->assertJsonPath('order.allowed_transitions.status.0', Order::STATUS_PROCESSING)
            ->assertJsonPath('order.allowed_transitions.payment_status.0', Order::PAYMENT_PARTIALLY_REFUNDED)
            ->assertJsonPath('order.allowed_transitions.fulfillment_status.0', Order::FULFILLMENT_FULFILLED);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $store->id,
            'status' => Order::STATUS_CONFIRMED,
            'payment_status' => Order::PAYMENT_PAID,
            'fulfillment_status' => Order::FULFILLMENT_PARTIAL,
        ]);
    }

    public function test_staff_cannot_update_an_order(): void
    {
        $merchant = User::factory()->create();
        $store = $this->store('staff-read-only-store');
        $order = $this->order($store, 'ORD-READ-ONLY');
        $this->assign($merchant, $store, StoreMembership::ROLE_STAFF);

        $this->actingAs($merchant, 'web')
            ->patchJson("/api/v1/merchant/stores/{$store->id}/orders/{$order->id}", [
                'status' => Order::STATUS_CONFIRMED,
            ])
            ->assertForbidden();

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_terminal_order_states_cannot_be_reversed(): void
    {
        $merchant = User::factory()->create();
        $store = $this->store('terminal-state-store');
        $order = $this->order($store, 'ORD-TERMINAL', [
            'status' => Order::STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_REFUNDED,
            'fulfillment_status' => Order::FULFILLMENT_RETURNED,
        ]);
        $this->assign($merchant, $store);

        $this->actingAs($merchant, 'web')
            ->patchJson("/api/v1/merchant/stores/{$store->id}/orders/{$order->id}", [
                'status' => Order::STATUS_PROCESSING,
                'payment_status' => Order::PAYMENT_PENDING,
                'fulfillment_status' => Order::FULFILLMENT_UNFULFILLED,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'payment_status', 'fulfillment_status']);

        $order->refresh();
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertSame(Order::PAYMENT_REFUNDED, $order->payment_status);
        $this->assertSame(Order::FULFILLMENT_RETURNED, $order->fulfillment_status);
    }

    public function test_order_update_requires_a_field_and_the_order_must_belong_to_the_store(): void
    {
        $merchant = User::factory()->create();
        $store = $this->store('update-owner-store');
        $otherStore = $this->store('update-other-store');
        $order = $this->order($store, 'ORD-OWNER');
        $otherOrder = $this->order($otherStore, 'ORD-OTHER');
        $this->assign($merchant, $store);

        $this->actingAs($merchant, 'web')
            ->patchJson("/api/v1/merchant/stores/{$store->id}/orders/{$order->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $this->actingAs($merchant, 'web')
            ->patchJson("/api/v1/merchant/stores/{$store->id}/orders/{$otherOrder->id}", [
                'status' => Order::STATUS_CONFIRMED,
            ])
            ->assertNotFound();
    }

    private function store(string $slug): Store
    {
        $organization = Organization::create([
            'name' => str($slug)->headline(),
            'slug' => $slug.'-organization',
            'status' => Organization::STATUS_ACTIVE,
        ]);

        return Store::create([
            'organization_id' => $organization->id,
            'name' => str($slug)->headline(),
            'slug' => $slug,
            'status' => Store::STATUS_ACTIVE,
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
        ]);
    }

    private function assign(User $merchant, Store $store, string $role = StoreMembership::ROLE_OWNER): void
    {
        StoreMembership::create([
            'store_id' => $store->id,
            'user_id' => $merchant->id,
            'role' => $role,
            'status' => StoreMembership::STATUS_ACTIVE,
        ]);
    }

    private function order(Store $store, string $number, array $attributes = []): Order
    {
        return Order::create(array_merge([
            'store_id' => $store->id,
            'order_number' => $number,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_PENDING,
            'fulfillment_status' => Order::FULFILLMENT_UNFULFILLED,
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.test',
            'items_count' => 2,
            'subtotal_amount' => 1000,
            'total_amount' => 1000,
            'currency' => 'INR',
            'placed_at' => now(),
        ], $attributes));
    }
}
