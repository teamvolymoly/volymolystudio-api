<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;

class StorePolicy
{
    public function view(User $user, Store $store): bool
    {
        if (! $this->storeIsActive($store)) {
            return false;
        }

        $role = $this->activeMembershipRole($user, $store);

        return StoreMembership::permissionsForRole($role)['store']['view'];
    }

    public function viewOrders(User $user, Store $store): bool
    {
        if (! $this->storeIsActive($store)) {
            return false;
        }

        $role = $this->activeMembershipRole($user, $store);

        return StoreMembership::permissionsForRole($role)['orders']['view'];
    }

    public function manageOrders(User $user, Store $store): bool
    {
        if (! $this->storeIsActive($store)) {
            return false;
        }

        $role = $this->activeMembershipRole($user, $store);

        return StoreMembership::permissionsForRole($role)['orders']['manage'];
    }

    public function viewProducts(User $user, Store $store): bool
    {
        if (! $this->storeIsActive($store)) {
            return false;
        }

        $role = $this->activeMembershipRole($user, $store);

        return StoreMembership::permissionsForRole($role)['products']['view'];
    }

    private function activeMembershipRole(User $user, Store $store): ?string
    {
        return $store->memberships()
            ->where('user_id', $user->id)
            ->where('status', StoreMembership::STATUS_ACTIVE)
            ->value('role');
    }

    private function storeIsActive(Store $store): bool
    {
        if ($store->status !== Store::STATUS_ACTIVE) {
            return false;
        }

        $store->loadMissing('organization:id,status');

        return $store->organization?->status === Organization::STATUS_ACTIVE;
    }
}
