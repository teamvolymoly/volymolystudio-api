<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function assign(User $merchant, Store $store): void
    {
        StoreMembership::create([
            'store_id' => $store->id,
            'user_id' => $merchant->id,
            'role' => StoreMembership::ROLE_OWNER,
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
