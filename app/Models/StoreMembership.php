<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreMembership extends Model
{
    use HasFactory;

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_STAFF = 'staff';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /** @return list<string> */
    public static function roles(): array
    {
        return [
            self::ROLE_OWNER,
            self::ROLE_ADMIN,
            self::ROLE_MANAGER,
            self::ROLE_STAFF,
        ];
    }

    /**
     * Return the store capabilities exposed to the merchant workspace.
     *
     * @return array{
     *     store: array{view: bool, manage: bool},
     *     orders: array{view: bool, manage: bool},
     *     products: array{view: bool, manage: bool},
     *     members: array{manage: bool}
     * }
     */
    public static function permissionsForRole(?string $role): array
    {
        $canViewStore = in_array($role, self::roles(), true);
        $canAdministerStore = in_array($role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
        $canManageOrders = in_array($role, [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MANAGER], true);
        $canManageProducts = in_array($role, [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MANAGER], true);

        return [
            'store' => [
                'view' => $canViewStore,
                'manage' => $canAdministerStore,
            ],
            'orders' => [
                'view' => $canViewStore,
                'manage' => $canManageOrders,
            ],
            'products' => [
                'view' => $canViewStore,
                'manage' => $canManageProducts,
            ],
            'members' => [
                'manage' => $canAdministerStore,
            ],
        ];
    }

    protected $fillable = ['store_id', 'user_id', 'role', 'status'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
