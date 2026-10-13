<?php

use App\Http\Controllers\Api\Platform\PlatformAuthController;
use App\Http\Controllers\Api\Platform\PlatformMerchantController;
use App\Http\Controllers\Api\Platform\PlatformPasswordController;
use App\Http\Controllers\Api\Platform\PlatformTenancyController;
use App\Http\Middleware\EnsurePlatformAdminSessionVersion;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', EnsurePlatformAdminSessionVersion::class])
    ->prefix('v1/platform/auth')
    ->group(function () {
        Route::get('/csrf-token', [PlatformAuthController::class, 'csrfToken']);
        Route::post('/login', [PlatformAuthController::class, 'login'])
            ->middleware('throttle:platform-auth-login');
        Route::post('/password/forgot', [PlatformPasswordController::class, 'forgot'])
            ->middleware('throttle:platform-auth-password-forgot');
        Route::post('/password/reset', [PlatformPasswordController::class, 'reset'])
            ->middleware('throttle:platform-auth-password-reset');

        Route::middleware('auth:platform')->group(function () {
            Route::get('/me', [PlatformAuthController::class, 'me']);
            Route::post('/logout', [PlatformAuthController::class, 'logout']);
        });
    });

Route::middleware(['web', EnsurePlatformAdminSessionVersion::class, 'auth:platform'])
    ->prefix('v1/platform')
    ->group(function () {
        Route::get('/tenancy', [PlatformTenancyController::class, 'index']);
        Route::get('/merchants', [PlatformMerchantController::class, 'index']);
        Route::post('/merchants', [PlatformMerchantController::class, 'store'])
            ->middleware('throttle:platform-merchant-invitations');
        Route::post('/merchants/{merchant}/invitation', [PlatformMerchantController::class, 'resendInvitation'])
            ->middleware('throttle:platform-merchant-invitations');
        Route::patch('/merchants/{merchant}/status', [PlatformMerchantController::class, 'updateStatus']);
        Route::put('/merchants/{merchant}/organizations/{organization}', [PlatformMerchantController::class, 'updateOrganizationAccess']);
        Route::delete('/merchants/{merchant}/organizations/{organization}', [PlatformMerchantController::class, 'removeOrganizationAccess']);
        Route::put('/merchants/{merchant}/stores/{store}', [PlatformMerchantController::class, 'updateStoreAccess']);
        Route::delete('/merchants/{merchant}/stores/{store}', [PlatformMerchantController::class, 'removeStoreAccess']);
        Route::post('/organizations', [PlatformTenancyController::class, 'storeOrganization']);
        Route::post('/stores', [PlatformTenancyController::class, 'storeStore']);
        Route::patch('/stores/{store}/status', [PlatformTenancyController::class, 'updateStoreStatus']);
    });
