<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminAuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;

class PlatformAuditLogger
{
    public function __construct(private readonly LoginClientContext $clientContext) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        PlatformAdmin $admin,
        Request $request,
        string $action,
        ?Organization $organization = null,
        ?Store $store = null,
        array $metadata = [],
        ?User $merchant = null,
    ): PlatformAdminAuditLog {
        $context = $this->clientContext->read($request);

        return PlatformAdminAuditLog::create([
            'platform_admin_id' => $admin->id,
            'organization_id' => $organization?->id,
            'store_id' => $store?->id,
            'merchant_user_id' => $merchant?->id,
            'action' => $action,
            'metadata' => $metadata,
            'ip_address' => $context['ip_address'],
            'user_agent' => $this->clientContext->userAgent($request),
        ]);
    }
}
