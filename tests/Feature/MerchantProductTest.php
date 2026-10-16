<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class MerchantProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_lists_only_products_from_an_assigned_store(): void
    {
        $merchant = User::factory()->create();
        $assignedStore = $this->store('assigned-product-store');
        $otherStore = $this->store('other-product-store');
        $this->assign($merchant, $assignedStore);

        $first = $this->product($assignedStore, 'First Product', ['status' => Product::STATUS_ACTIVE]);
        $second = $this->product($assignedStore, 'Second Product', ['inventory' => 0]);
        $this->product($otherStore, 'Private Product');

        $this->actingAs($merchant, 'web')
            ->getJson("/api/v1/merchant/stores/{$assignedStore->id}/products")
            ->assertOk()
            ->assertJsonCount(2, 'products')
            ->assertJsonPath('store.id', $assignedStore->id)
            ->assertJsonPath('products.0.id', $first->id)
            ->assertJsonPath('products.0.status', Product::STATUS_ACTIVE)
            ->assertJsonPath('products.1.id', $second->id)
            ->assertJsonPath('products.1.inventory', 0)
            ->assertJsonPath('products.1.product_type', 'Skin Care');
    }

    public function test_guest_and_unassigned_merchant_cannot_read_store_products(): void
    {
        $store = $this->store('protected-product-store');
        $otherMerchant = User::factory()->create();

        $this->getJson("/api/v1/merchant/stores/{$store->id}/products")
            ->assertUnauthorized();

        $this->actingAs($otherMerchant, 'web')
            ->getJson("/api/v1/merchant/stores/{$store->id}/products")
            ->assertForbidden();
    }

    public function test_every_supported_store_role_can_view_products(): void
    {
        foreach (StoreMembership::roles() as $role) {
            $merchant = User::factory()->create();
            $store = $this->store("{$role}-product-store");
            $this->assign($merchant, $store, $role);

            $this->assertTrue(
                Gate::forUser($merchant)->allows('viewProducts', $store),
                "The {$role} role should be allowed to view products.",
            );
        }
    }

    public function test_demo_product_seed_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $store = Store::query()->where('slug', 'my-store')->firstOrFail();

        $this->assertDatabaseCount('stores', 1);
        $this->assertDatabaseCount('products', 4);
        $this->assertSame(4, $store->products()->count());
        $this->assertSame(1, $store->products()->where('status', Product::STATUS_ACTIVE)->count());
        $this->assertSame(2, $store->products()->where('status', Product::STATUS_DRAFT)->count());
        $this->assertSame(1, $store->products()->where('status', Product::STATUS_UNLISTED)->count());
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

    private function product(Store $store, string $name, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'store_id' => $store->id,
            'name' => $name,
            'status' => Product::STATUS_DRAFT,
            'inventory' => 10,
            'category' => 'Uncategorized',
            'channels' => 2,
            'product_type' => 'Skin Care',
            'vendor' => 'My Store',
        ], $attributes));
    }
}
