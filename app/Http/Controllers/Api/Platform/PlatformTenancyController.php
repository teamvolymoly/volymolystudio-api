<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\CreatePlatformOrganizationRequest;
use App\Http\Requests\Platform\CreatePlatformStoreRequest;
use App\Http\Requests\Platform\UpdatePlatformStoreStatusRequest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PlatformAdmin;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use App\Services\PlatformAuditLogger;
use App\Services\UniqueSlugGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformTenancyController extends Controller
{
    public function __construct(
        private readonly UniqueSlugGenerator $slugs,
        private readonly PlatformAuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizations = Organization::query()
            ->withCount('stores')
            ->with(['memberships' => fn ($query) => $query
                ->where('role', OrganizationMembership::ROLE_OWNER)
                ->where('status', OrganizationMembership::STATUS_ACTIVE)
                ->with('user:id,name,email')])
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Organization $organization) => $this->organizationPayload($organization));

        $stores = Store::query()
            ->with(['organization:id,name,slug,status', 'memberships' => fn ($query) => $query
                ->where('role', StoreMembership::ROLE_OWNER)
                ->where('status', StoreMembership::STATUS_ACTIVE)
                ->with('user:id,name,email')])
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Store $store) => $this->storePayload($store));

        return response()->json([
            'organizations' => $organizations,
            'stores' => $stores,
            'summary' => [
                'organizations' => Organization::count(),
                'stores' => Store::count(),
                'active_stores' => Store::where('status', Store::STATUS_ACTIVE)->count(),
                'merchants' => OrganizationMembership::where('role', OrganizationMembership::ROLE_OWNER)
                    ->where('status', OrganizationMembership::STATUS_ACTIVE)
                    ->distinct('user_id')->count('user_id'),
            ],
        ]);
    }

    public function storeOrganization(CreatePlatformOrganizationRequest $request): JsonResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $data = $request->validated();
        $owner = User::where('email', $data['owner_email'])->first();

        if (! $owner || ! $owner->isActive()) {
            return response()->json([
                'message' => 'Select an active merchant account.',
                'errors' => ['owner_email' => ['The merchant account does not exist or is suspended.']],
            ], 422);
        }

        $organization = DB::transaction(function () use ($admin, $owner, $data, $request): Organization {
            $organization = Organization::create([
                'name' => $data['name'],
                'slug' => $this->slugs->generate($data['name'], Organization::class),
                'status' => Organization::STATUS_ACTIVE,
                'created_by_platform_admin_id' => $admin->id,
            ]);

            OrganizationMembership::create([
                'organization_id' => $organization->id,
                'user_id' => $owner->id,
                'role' => OrganizationMembership::ROLE_OWNER,
                'status' => OrganizationMembership::STATUS_ACTIVE,
            ]);

            $this->audit->record($admin, $request, 'organization.created', $organization, metadata: [
                'name' => $organization->name,
                'owner_user_id' => $owner->id,
                'owner_email' => $owner->email,
            ]);

            return $organization;
        });

        $organization->loadCount('stores')->load(['memberships.user:id,name,email']);

        return response()->json([
            'message' => 'Organization created and merchant owner assigned.',
            'organization' => $this->organizationPayload($organization),
        ], 201);
    }

    public function storeStore(CreatePlatformStoreRequest $request): JsonResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $data = $request->validated();
        $organization = Organization::findOrFail($data['organization_id']);

        if ($organization->status !== Organization::STATUS_ACTIVE) {
            return response()->json([
                'message' => 'A store cannot be created under a suspended organization.',
                'errors' => ['organization_id' => ['Select an active organization.']],
            ], 422);
        }

        $store = DB::transaction(function () use ($admin, $organization, $data, $request): Store {
            $store = Store::create([
                'organization_id' => $organization->id,
                'name' => $data['name'],
                'slug' => $this->slugs->generate($data['name'], Store::class),
                'status' => Store::STATUS_ACTIVE,
                'currency' => $data['currency'],
                'timezone' => $data['timezone'],
                'created_by_platform_admin_id' => $admin->id,
            ]);

            $ownerIds = $organization->memberships()
                ->where('role', OrganizationMembership::ROLE_OWNER)
                ->where('status', OrganizationMembership::STATUS_ACTIVE)
                ->pluck('user_id');

            foreach ($ownerIds as $ownerId) {
                StoreMembership::create([
                    'store_id' => $store->id,
                    'user_id' => $ownerId,
                    'role' => StoreMembership::ROLE_OWNER,
                    'status' => StoreMembership::STATUS_ACTIVE,
                ]);
            }

            $this->audit->record($admin, $request, 'store.created', $organization, $store, [
                'name' => $store->name,
                'owner_user_ids' => $ownerIds->values()->all(),
            ]);

            return $store;
        });

        $store->load(['organization:id,name,slug,status', 'memberships.user:id,name,email']);

        return response()->json([
            'message' => 'Store created and organization owners connected.',
            'store' => $this->storePayload($store),
        ], 201);
    }

    public function updateStoreStatus(
        UpdatePlatformStoreStatusRequest $request,
        Store $store,
    ): JsonResponse {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $previous = $store->status;
        $next = $request->validated('status');

        DB::transaction(function () use ($admin, $request, $store, $previous, $next): void {
            $store->update(['status' => $next]);
            $this->audit->record($admin, $request, 'store.status_changed', $store->organization, $store, [
                'from' => $previous,
                'to' => $next,
            ]);
        });

        $store->load(['organization:id,name,slug,status', 'memberships.user:id,name,email']);

        return response()->json([
            'message' => 'Store status updated.',
            'store' => $this->storePayload($store),
        ]);
    }

    /** @return array<string, mixed> */
    private function organizationPayload(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status,
            'stores_count' => (int) ($organization->stores_count ?? $organization->stores()->count()),
            'owners' => $organization->memberships->map(fn (OrganizationMembership $membership) => [
                'id' => $membership->user?->id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
            ])->values(),
            'created_at' => $organization->created_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function storePayload(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status,
            'currency' => $store->currency,
            'timezone' => $store->timezone,
            'organization' => [
                'id' => $store->organization?->id,
                'name' => $store->organization?->name,
                'status' => $store->organization?->status,
            ],
            'owners' => $store->memberships->map(fn (StoreMembership $membership) => [
                'id' => $membership->user?->id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
            ])->values(),
            'created_at' => $store->created_at?->toISOString(),
        ];
    }
}
