<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoginActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginActivityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $token = $request->query('token');
        $activity = is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token)
            ? LoginActivity::where('review_token_hash', hash('sha256', $token))->first()
            : null;
        $headers = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];
        if (! $activity || $activity->review_expires_at->lte(now())) {
            return response()->json(['message' => 'This activity link is invalid or expired.'], 410, $headers);
        }

        // Read-only: opening/scanning an email link never authenticates or revokes sessions.
        return response()->json(['activity' => [
            'email' => $activity->email,
            'device' => $activity->device,
            'location' => $activity->location ?? 'Unavailable',
            'ip_address' => $activity->ip_address ?? 'Unavailable',
            'signed_in_at' => $activity->created_at->toIso8601String(),
        ]], 200, $headers);
    }
}
