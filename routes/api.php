<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\LoginOtpController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Return the session-bound token to the frontend. This also works when the
// frontend runs on a separate origin and cannot read the XSRF-TOKEN cookie.
Route::middleware(['web', \App\Http\Middleware\IdentifyLoginBrowser::class, \App\Http\Middleware\EnsureSessionVersion::class])->get('/auth/csrf-token', static fn (Request $request) => response()->json([
    'token' => $request->session()->token(),
]));

// These endpoints use Laravel's session guard. Apply web middleware once for
// session storage and CSRF validation.
Route::middleware(['web', \App\Http\Middleware\IdentifyLoginBrowser::class, \App\Http\Middleware\EnsureSessionVersion::class])->prefix('auth')->group(function () {
    Route::get('/security/activity', [\App\Http\Controllers\Api\LoginActivityController::class, 'show'])->middleware('throttle:auth-security-activity');
    Route::get('/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:auth-google-redirect');
    Route::get('/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:auth-google-callback');
    Route::post('/google/link', [GoogleAuthController::class, 'link'])->middleware('throttle:auth-google-link');
    Route::post('/login', [LoginOtpController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/login/verify', [LoginOtpController::class, 'verify'])->middleware('throttle:auth-login-verify');
    Route::post('/login/resend', [LoginOtpController::class, 'resend'])->middleware('throttle:auth-login-resend');
    Route::post('/verification/send', [AuthController::class, 'sendVerificationCode'])->middleware('throttle:auth-verification-send');
    Route::post('/verification/verify', [AuthController::class, 'verifyCode'])->middleware('throttle:auth-verification-verify');
    Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth-password-forgot');
    Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:auth-password-reset');
    Route::post('/account/recover', [AuthController::class, 'startAccountRecovery'])->middleware('throttle:auth-account-recover');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
