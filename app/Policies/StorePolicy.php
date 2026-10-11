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
        if ($store->status !== Store::STATUS_ACTIVE) {
            return false;
        }

        $store->loadMissing('organization:id,status');
        if ($store->organization?->status !== Organization::STATUS_ACTIVE) {
            return false;
        }

        return $store->memberships()
            ->where('user_id', $user->id)
            ->where('status', StoreMembership::STATUS_ACTIVE)
            ->exists();
    }
}
