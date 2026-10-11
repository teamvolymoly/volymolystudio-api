<?php

use App\Http\Controllers\Api\MerchantStoreController;
use App\Http\Middleware\EnsureSessionVersion;
use App\Http\Middleware\IdentifyLoginBrowser;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', IdentifyLoginBrowser::class, EnsureSessionVersion::class, 'auth:sanctum'])
    ->prefix('v1/merchant')
    ->group(function () {
        Route::get('/stores', [MerchantStoreController::class, 'index']);
        Route::get('/stores/{store}', [MerchantStoreController::class, 'show']);
    });
