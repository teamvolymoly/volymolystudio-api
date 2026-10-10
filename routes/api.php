<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\LoginActivityController;
use App\Http\Controllers\Api\LoginOtpController;
use App\Http\Middleware\EnsureSessionVersion;
use App\Http\Middleware\IdentifyLoginBrowser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Return the session-bound token to the frontend. This also works when the
// frontend runs on a separate origin and cannot read the XSRF-TOKEN cookie.
Route::middleware(['web', IdentifyLoginBrowser::class, EnsureSessionVersion::class])->get('/auth/csrf-token', static fn (Request $request) => response()->json([
    'token' => $request->session()->token(),
]));

// These endpoints use Laravel's session guard. Apply web middleware once for
// session storage and CSRF validation.
Route::middleware(['web', IdentifyLoginBrowser::class, EnsureSessionVersion::class])->prefix('auth')->group(function () {
    Route::get('/security/activity', [LoginActivityController::class, 'show'])->middleware('throttle:auth-security-activity');
    Route::post('/security/secure', [LoginActivityController::class, 'secure'])->middleware('throttle:auth-security-secure');
    Route::get('/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:auth-google-redirect');
    Route::get('/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:auth-google-callback');
    Route::get('/google/link-context', [GoogleAuthController::class, 'linkContext'])->middleware('throttle:auth-google-link-context');
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
