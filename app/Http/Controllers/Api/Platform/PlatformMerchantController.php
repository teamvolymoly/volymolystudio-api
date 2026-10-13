<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\CreateMerchantAccountRequest;
use App\Http\Requests\Platform\UpdateMerchantOrganizationAccessRequest;
use App\Http\Requests\Platform\UpdateMerchantStatusRequest;
use App\Http\Requests\Platform\UpdateMerchantStoreAccessRequest;
use App\Jobs\SendMerchantInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PlatformAdmin;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use App\Services\PlatformAuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformMerchantController extends Controller
{
    public function __construct(private readonly PlatformAuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in([User::STATUS_ACTIVE, User::STATUS_SUSPENDED])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $query = User::query()
            ->withCount([
                'organizationMemberships as organizations_count' => fn ($query) => $query
                    ->where('status', OrganizationMembership::STATUS_ACTIVE),
                'storeMemberships as stores_count' => fn ($query) => $query
                    ->where('status', StoreMembership::STATUS_ACTIVE),
            ])
            ->with(['organizationMemberships' => fn ($query) => $query
                ->where('status', OrganizationMembership::STATUS_ACTIVE)
                ->with('organization:id,name,slug,status'),
                'storeMemberships' => fn ($query) => $query
                    ->where('status', StoreMembership::STATUS_ACTIVE)
                    ->with('store.organization:id,name,slug,status'),
            ])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->latest('id');

        $merchants = $query->paginate((int) ($filters['per_page'] ?? 20));

        return response()->json([
            'merchants' => $merchants->getCollection()
                ->map(fn (User $merchant) => $this->merchantPayload($merchant))
                ->values(),
            'summary' => [
                'total' => User::count(),
                'active' => User::where('status', User::STATUS_ACTIVE)->count(),
                'suspended' => User::where('status', User::STATUS_SUSPENDED)->count(),
                'verified' => User::whereNotNull('email_verified_at')->count(),
            ],
            'pagination' => [
                'page' => $merchants->currentPage(),
                'per_page' => $merchants->perPage(),
                'last_page' => $merchants->lastPage(),
                'total' => $merchants->total(),
            ],
        ]);
    }

    public function store(CreateMerchantAccountRequest $request): JsonResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $data = $request->validated();

        try {
            $merchant = DB::transaction(function () use ($admin, $data, $request): User {
                $merchant = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => null,
                    'email_verified_at' => null,
                    'status' => User::STATUS_ACTIVE,
                ]);

                $this->audit->record($admin, $request, 'merchant.created', metadata: [
                    'merchant_user_id' => $merchant->id,
                    'merchant_email' => $merchant->email,
                ], merchant: $merchant);

                return $merchant;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => ['A merchant account with this email already exists.'],
            ]);
        }

        $this->sendInvitation($merchant);
        $this->loadMerchantContext($merchant);

        return response()->json([
            'message' => 'Merchant account created and invitation queued.',
            'merchant' => $this->merchantPayload($merchant),
        ], 201);
    }

    public function resendInvitation(Request $request, User $merchant): JsonResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        if (! $merchant->isActive()) {
            throw ValidationException::withMessages([
                'merchant' => ['Activate this merchant account before sending an invitation.'],
            ]);
        }
        if ($merchant->hasCompletedAccountSetup()) {
            throw ValidationException::withMessages([
                'merchant' => ['This merchant account has already completed setup.'],
            ]);
        }

        $this->sendInvitation($merchant);
        $this->audit->record($admin, $request, 'merchant.invitation_resent', metadata: [
            'merchant_user_id' => $merchant->id,
            'merchant_email' => $merchant->email,
        ], merchant: $merchant);

        return response()->json([
            'message' => 'Merchant invitation queued.',
        ], 202);
    }

    public function updateOrganizationAccess(
        UpdateMerchantOrganizationAccessRequest $request,
        User $merchant,
        Organization $organization,
    ): JsonResponse {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $role = $request->validated('role');

        DB::transaction(function () use ($admin, $merchant, $organization, $request, $role): void {
            OrganizationMembership::updateOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $merchant->id],
                ['role' => $role, 'status' => OrganizationMembership::STATUS_ACTIVE],
            );

            $storeIds = $organization->stores()->pluck('id');
            if ($role === OrganizationMembership::ROLE_OWNER) {
                foreach ($storeIds as $storeId) {
                    StoreMembership::updateOrCreate(
                        ['store_id' => $storeId, 'user_id' => $merchant->id],
                        ['role' => StoreMembership::ROLE_OWNER, 'status' => StoreMembership::STATUS_ACTIVE],
                    );
                }
            } elseif ($storeIds->isNotEmpty()) {
                StoreMembership::where('user_id', $merchant->id)
                    ->whereIn('store_id', $storeIds)
                    ->where('role', StoreMembership::ROLE_OWNER)
                    ->delete();
            }

            $this->audit->record($admin, $request, 'merchant.organization_access_updated', $organization, metadata: [
                'merchant_user_id' => $merchant->id,
                'merchant_email' => $merchant->email,
                'role' => $role,
            ], merchant: $merchant);
        });

        $this->loadMerchantContext($merchant);

        return response()->json([
            'message' => 'Merchant organization access updated.',
            'merchant' => $this->merchantPayload($merchant),
        ]);
    }

    public function removeOrganizationAccess(
        Request $request,
        User $merchant,
        Organization $organization,
    ): JsonResponse {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        DB::transaction(function () use ($admin, $merchant, $organization, $request): void {
            OrganizationMembership::where('organization_id', $organization->id)
                ->where('user_id', $merchant->id)
                ->delete();
            StoreMembership::where('user_id', $merchant->id)
                ->whereIn('store_id', $organization->stores()->select('id'))
                ->delete();

            $this->audit->record($admin, $request, 'merchant.organization_access_removed', $organization, metadata: [
                'merchant_user_id' => $merchant->id,
                'merchant_email' => $merchant->email,
            ], merchant: $merchant);
        });

        $this->loadMerchantContext($merchant);

        return response()->json([
            'message' => 'Merchant organization access removed.',
            'merchant' => $this->merchantPayload($merchant),
        ]);
    }

    public function updateStoreAccess(
        UpdateMerchantStoreAccessRequest $request,
        User $merchant,
        Store $store,
    ): JsonResponse {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $role = $request->validated('role');

        DB::transaction(function () use ($admin, $merchant, $request, $role, $store): void {
            StoreMembership::updateOrCreate(
                ['store_id' => $store->id, 'user_id' => $merchant->id],
                ['role' => $role, 'status' => StoreMembership::STATUS_ACTIVE],
            );

            $this->audit->record($admin, $request, 'merchant.store_access_updated', $store->organization, $store, [
                'merchant_user_id' => $merchant->id,
                'merchant_email' => $merchant->email,
                'role' => $role,
            ], merchant: $merchant);
        });

        $this->loadMerchantContext($merchant);

        return response()->json([
            'message' => 'Merchant store access updated.',
            'merchant' => $this->merchantPayload($merchant),
        ]);
    }

    public function removeStoreAccess(Request $request, User $merchant, Store $store): JsonResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        DB::transaction(function () use ($admin, $merchant, $request, $store): void {
            StoreMembership::where('store_id', $store->id)
                ->where('user_id', $merchant->id)
                ->delete();

            $this->audit->record($admin, $request, 'merchant.store_access_removed', $store->organization, $store, [
                'merchant_user_id' => $merchant->id,
                'merchant_email' => $merchant->email,
            ], merchant: $merchant);
        });

        $this->loadMerchantContext($merchant);

        return response()->json([
            'message' => 'Merchant store access removed.',
            'merchant' => $this->merchantPayload($merchant),
        ]);
    }

    public function updateStatus(UpdateMerchantStatusRequest $request, User $merchant): JsonResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $next = $request->validated('status');

        $merchant = DB::transaction(function () use ($admin, $merchant, $next, $request): User {
            $locked = User::whereKey($merchant->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->status;

            if ($previous === $next) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $next,
                'suspended_at' => $next === User::STATUS_SUSPENDED ? now() : null,
                'suspended_by_platform_admin_id' => $next === User::STATUS_SUSPENDED ? $admin->id : null,
                'auth_session_version' => (int) $locked->auth_session_version + 1,
                'remember_token' => Str::random(60),
            ])->save();

            $locked->tokens()->delete();
            if ($next === User::STATUS_SUSPENDED) {
                Password::broker('users')->deleteToken($locked);
            }
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table(config('session.table', 'sessions'))
                    ->where('user_id', $locked->id)
                    ->delete();
            }

            $this->audit->record($admin, $request, 'merchant.status_changed', metadata: [
                'merchant_user_id' => $locked->id,
                'merchant_email' => $locked->email,
                'from' => $previous,
                'to' => $next,
            ], merchant: $locked);

            return $locked;
        }, 3);

        $this->loadMerchantContext($merchant);

        return response()->json([
            'message' => $merchant->status === User::STATUS_ACTIVE
                ? 'Merchant account activated.'
                : 'Merchant account suspended and signed out.',
            'merchant' => $this->merchantPayload($merchant),
        ]);
    }

    /** @return array<string, mixed> */
    private function merchantPayload(User $merchant): array
    {
        return [
            'id' => $merchant->id,
            'name' => $merchant->name,
            'email' => $merchant->email,
            'status' => $merchant->status,
            'email_verified' => $merchant->email_verified_at !== null,
            'setup_status' => $merchant->hasCompletedAccountSetup() ? 'complete' : 'pending',
            'organizations_count' => (int) ($merchant->organizations_count ?? 0),
            'stores_count' => (int) ($merchant->stores_count ?? 0),
            'organizations' => $merchant->organizationMemberships
                ->map(fn (OrganizationMembership $membership) => [
                    'id' => $membership->organization?->id,
                    'name' => $membership->organization?->name,
                    'slug' => $membership->organization?->slug,
                    'status' => $membership->organization?->status,
                    'role' => $membership->role,
                ])->filter(fn (array $organization) => $organization['id'] !== null)->values(),
            'stores' => $merchant->storeMemberships
                ->map(fn (StoreMembership $membership) => [
                    'id' => $membership->store?->id,
                    'name' => $membership->store?->name,
                    'slug' => $membership->store?->slug,
                    'status' => $membership->store?->status,
                    'role' => $membership->role,
                    'organization' => [
                        'id' => $membership->store?->organization?->id,
                        'name' => $membership->store?->organization?->name,
                        'status' => $membership->store?->organization?->status,
                    ],
                ])->filter(fn (array $store) => $store['id'] !== null)->values(),
            'suspended_at' => $merchant->suspended_at?->toISOString(),
            'created_at' => $merchant->created_at?->toISOString(),
        ];
    }

    private function sendInvitation(User $merchant): void
    {
        $token = Password::broker('users')->createToken($merchant);

        SendMerchantInvitationMail::dispatch(
            merchantId: $merchant->id,
            email: $merchant->email,
            token: $token,
            setupUrl: $merchant->passwordResetUrl($token),
        );
    }

    private function loadMerchantContext(User $merchant): void
    {
        $merchant->loadCount([
            'organizationMemberships as organizations_count' => fn ($query) => $query
                ->where('status', OrganizationMembership::STATUS_ACTIVE),
            'storeMemberships as stores_count' => fn ($query) => $query
                ->where('status', StoreMembership::STATUS_ACTIVE),
        ])->load([
            'organizationMemberships' => fn ($query) => $query
                ->where('status', OrganizationMembership::STATUS_ACTIVE)
                ->with('organization:id,name,slug,status'),
            'storeMemberships' => fn ($query) => $query
                ->where('status', StoreMembership::STATUS_ACTIVE)
                ->with('store.organization:id,name,slug,status'),
        ]);
    }
}
