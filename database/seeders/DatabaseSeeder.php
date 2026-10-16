<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $user = User::query()->where('email', 'test@example.com')->first()
            ?? User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'test-organization'],
            ['name' => 'Test Organization', 'status' => Organization::STATUS_ACTIVE],
        );
        $store = Store::query()->firstOrCreate(
            ['slug' => 'my-store'],
            [
                'organization_id' => $organization->id,
                'name' => 'My Store',
                'status' => Store::STATUS_ACTIVE,
                'currency' => 'INR',
                'timezone' => 'Asia/Kolkata',
            ],
        );

        OrganizationMembership::query()->updateOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $user->id],
            ['role' => OrganizationMembership::ROLE_OWNER, 'status' => OrganizationMembership::STATUS_ACTIVE],
        );
        StoreMembership::query()->updateOrCreate(
            ['store_id' => $store->id, 'user_id' => $user->id],
            ['role' => StoreMembership::ROLE_OWNER, 'status' => StoreMembership::STATUS_ACTIVE],
        );

        $this->call(ProductSeeder::class);
    }
}
