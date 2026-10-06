<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// The SPA is hosted on vercel.app while this API is hosted on volymoly.com.
// In that cross-site setup the browser will not expose the XSRF-TOKEN cookie
// to frontend JavaScript, so provide the session-bound token as JSON instead.
Route::middleware('web')->get('/auth/csrf-token', static fn (Request $request) => response()->json([
    'token' => $request->session()->token(),
]));

// These auth endpoints use Laravel's session guard. Apply the web middleware
// explicitly so session storage is available even when Sanctum's stateful
// domain detection is affected by a cached or missing production env value.
Route::middleware('web')->prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/verification/send', [AuthController::class, 'sendVerificationCode'])->middleware('throttle:6,1');
    Route::post('/verification/verify', [AuthController::class, 'verifyCode'])->middleware('throttle:12,1');
    Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
    Route::post('/account/recover', [AuthController::class, 'startAccountRecovery'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
