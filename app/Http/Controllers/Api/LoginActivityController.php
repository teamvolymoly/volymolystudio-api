<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailVerificationCode;
use App\Models\LoginActivity;
use App\Models\RecognizedLoginDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoginActivityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $activity = $this->findActivity(
            $request->header('x-activity-token', $request->query('token')),
        );
        if (! $activity || $activity->review_expires_at->lte(now())) {
            return $this->expired();
        }

        // Read-only: opening/scanning an email link never authenticates or revokes sessions.
        return response()->json(['activity' => [
            'email' => $activity->email,
            'device' => $activity->device,
            'location' => $activity->location ?? 'Unavailable',
            'ip_address' => $activity->ip_address ?? 'Unavailable',
            'signed_in_at' => $activity->created_at->toIso8601String(),
            'secured' => $activity->secured_at !== null,
            'secured_at' => $activity->secured_at?->toIso8601String(),
        ]], 200, $this->headers());
    }

    public function secure(Request $request): JsonResponse
    {
        $token = $request->input('token');
        if (! is_string($token) || ! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return $this->expired();
        }

        return DB::transaction(function () use ($token): JsonResponse {
            $activity = LoginActivity::where('review_token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $activity || $activity->review_expires_at->lte(now())) {
                return $this->expired();
            }

            if ($activity->secured_at) {
                return $this->securedResponse(true);
            }

            $user = User::whereKey($activity->user_id)->lockForUpdate()->first();
            if (! $user) {
                return $this->expired();
            }

            $securedAt = now();
            $user->forceFill([
                'remember_token' => Str::random(60),
                'auth_session_version' => (int) $user->auth_session_version + 1,
            ])->save();

            // Version checks revoke non-database sessions on their next request.
            // Database-backed sessions can be removed immediately as well.
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->delete();
            }

            $user->tokens()->delete();

            // A pending OTP could otherwise create a fresh session after this action.
            EmailVerificationCode::where('user_id', $user->id)
                ->where('purpose', 'login')
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $securedAt]);

            // Keep activity history, but require every browser to be recognized again.
            RecognizedLoginDevice::where('user_id', $user->id)
                ->update(['revoked_at' => $securedAt]);

            // Securing any valid alert resolves all outstanding alerts for the account,
            // making retries and older links idempotent instead of repeatedly revoking.
            LoginActivity::where('user_id', $user->id)
                ->whereNull('secured_at')
                ->update(['secured_at' => $securedAt]);

            return $this->securedResponse(false);
        }, 3);
    }

    private function findActivity(mixed $token): ?LoginActivity
    {
        return is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token)
            ? LoginActivity::where('review_token_hash', hash('sha256', $token))->first()
            : null;
    }

    private function expired(): JsonResponse
    {
        return response()->json([
            'message' => 'This activity link is invalid or expired.',
        ], 410, $this->headers());
    }

    private function securedResponse(bool $alreadySecured): JsonResponse
    {
        return response()->json([
            'message' => $alreadySecured
                ? 'This account has already been secured.'
                : 'Your sessions have been signed out and remembered devices have been revoked.',
            'secured' => true,
            'already_secured' => $alreadySecured,
        ], 200, $this->headers());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];
    }
}
