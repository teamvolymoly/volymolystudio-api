<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MerchantStoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $stores = $user->stores()
            ->wherePivot('status', StoreMembership::STATUS_ACTIVE)
            ->with('organization:id,name,slug,status')
            ->orderBy('stores.name')
            ->get()
            ->map(fn (Store $store) => $this->storePayload($store));

        return response()->json(['stores' => $stores]);
    }

    public function show(Request $request, Store $store): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('view', $store);
        $store->load('organization:id,name,slug,status');
        $role = $store->memberships()
            ->where('user_id', $user->id)
            ->where('status', StoreMembership::STATUS_ACTIVE)
            ->value('role');

        return response()->json(['store' => $this->storePayload($store, $role)]);
    }

    /** @return array<string, mixed> */
    private function storePayload(Store $store, ?string $role = null): array
    {
        $role ??= $store->pivot?->role;
        $permissions = StoreMembership::permissionsForRole($role);
        $accessReason = match (true) {
            $store->status !== Store::STATUS_ACTIVE => 'store_suspended',
            $store->organization?->status !== Organization::STATUS_ACTIVE => 'organization_suspended',
            ! $permissions['store']['view'] => 'role_not_allowed',
            default => null,
        };

        return [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status,
            'currency' => $store->currency,
            'timezone' => $store->timezone,
            'role' => $role,
            'permissions' => $permissions,
            'access' => [
                'available' => $accessReason === null,
                'reason' => $accessReason,
            ],
            'organization' => [
                'id' => $store->organization?->id,
                'name' => $store->organization?->name,
                'slug' => $store->organization?->slug,
                'status' => $store->organization?->status,
            ],
        ];
    }
}
