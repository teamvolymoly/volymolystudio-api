<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $store = Store::query()->where('slug', 'my-store')->first();
        if (! $store) {
            return;
        }

        $products = [
            [
                'name' => 'Lumeramist Glow Muse Invisible Finish Sunscreen SPF 50',
                'status' => Product::STATUS_ACTIVE,
                'inventory' => 500,
                'category' => 'Uncategorized',
                'channels' => 2,
                'product_type' => 'Skin Care',
                'vendor' => 'My Store',
            ],
            [
                'name' => 'Lumeramist Green Bloom Cleaning Form Facewash',
                'status' => Product::STATUS_DRAFT,
                'inventory' => 0,
                'category' => 'Skin Care Masks & Peels',
                'channels' => 2,
                'product_type' => 'Skin Care',
                'vendor' => 'My Store',
            ],
            [
                'name' => 'Lumeramist Velvet Night Retinol Cream',
                'status' => Product::STATUS_UNLISTED,
                'inventory' => 10,
                'category' => 'Uncategorized',
                'channels' => 2,
                'product_type' => 'Skin Care',
                'vendor' => 'My Store',
            ],
            [
                'name' => 'Lumeramist Skin Code PDRN Collagen Serum',
                'status' => Product::STATUS_DRAFT,
                'inventory' => 10,
                'category' => 'Uncategorized',
                'channels' => 2,
                'product_type' => 'Skin Care',
                'vendor' => 'My Store',
            ],
        ];

        foreach ($products as $product) {
            Product::query()->updateOrCreate(
                ['store_id' => $store->id, 'name' => $product['name']],
                $product,
            );
        }
    }
}
