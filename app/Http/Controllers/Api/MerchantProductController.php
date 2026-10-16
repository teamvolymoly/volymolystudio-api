<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MerchantProductController extends Controller
{
    public function index(Request $request, Store $store): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewProducts', $store);

        $products = $store->products()->orderBy('id')->get();

        return response()->json([
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
            ],
            'products' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'status' => $product->status,
                'inventory' => $product->inventory,
                'category' => $product->category,
                'channels' => $product->channels,
                'product_type' => $product->product_type,
                'vendor' => $product->vendor,
            ]),
        ]);
    }
}
