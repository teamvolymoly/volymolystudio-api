<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateMerchantStatusRequest;
use App\Models\OrganizationMembership;
use App\Models\PlatformAdmin;
use App\Models\StoreMembership;
use App\Models\User;
use App\Services\PlatformAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
                ->with('organization:id,name,slug,status')])
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

        $merchant->loadCount([
            'organizationMemberships as organizations_count' => fn ($query) => $query
                ->where('status', OrganizationMembership::STATUS_ACTIVE),
            'storeMemberships as stores_count' => fn ($query) => $query
                ->where('status', StoreMembership::STATUS_ACTIVE),
        ])->load(['organizationMemberships' => fn ($query) => $query
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->with('organization:id,name,slug,status')]);

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
            'suspended_at' => $merchant->suspended_at?->toISOString(),
            'created_at' => $merchant->created_at?->toISOString(),
        ];
    }
}
