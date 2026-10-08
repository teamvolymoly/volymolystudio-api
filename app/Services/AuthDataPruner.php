<?php

namespace App\Services;

use App\Models\AccountRecoveryRequest;
use App\Models\EmailVerificationCode;
use App\Models\LoginActivity;
use App\Models\RecognizedLoginDevice;

class AuthDataPruner
{
    /**
     * @return array<string, int>
     */
    public function prune(): array
    {
        $verificationCutoff = now()->subDays(max(1, (int) config('auth_retention.verification_days')));
        $recoveryCutoff = now()->subDays(max(1, (int) config('auth_retention.recovery_days')));
        $activityCutoff = now()->subDays(max(1, (int) config('auth_retention.activity_days')));
        $deviceCutoff = now()->subDays(max(1, (int) config('auth_retention.device_days')));

        $verifications = EmailVerificationCode::where(function ($query) use ($verificationCutoff): void {
            $query->where('expires_at', '<', $verificationCutoff)
                ->orWhere('consumed_at', '<', $verificationCutoff);
        })->delete();
        $recoveries = AccountRecoveryRequest::where(function ($query) use ($recoveryCutoff): void {
            $query->where('expires_at', '<', $recoveryCutoff)
                ->orWhere(function ($terminal) use ($recoveryCutoff): void {
                    $terminal->whereIn('status', ['completed', 'cancelled'])
                        ->where('updated_at', '<', $recoveryCutoff);
                });
        })->delete();
        $activities = LoginActivity::where('created_at', '<', $activityCutoff)->delete();
        $devices = RecognizedLoginDevice::where('last_seen_at', '<', $deviceCutoff)->delete();

        return compact('verifications', 'recoveries', 'activities', 'devices');
    }
}
