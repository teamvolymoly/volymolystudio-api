<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class GoogleAuthController extends Controller
{
    private const PENDING_LINK = 'google_oauth_link';

    public function redirect(Request $request): RedirectResponse
    {
        $request->session()->forget([self::PENDING_LINK, 'state', 'login_otp']);

        if (! config('services.google.client_id') || ! config('services.google.client_secret') || ! config('services.google.redirect')) {
            return $this->failure('not_configured');
        }

        // Socialite stores and verifies OAuth state in the existing session.
        return Socialite::driver('google')->with(['prompt' => 'select_account'])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $request->session()->forget(self::PENDING_LINK);

        if ($request->has('error') || ! is_string($request->query('code')) || $request->query('code') === '') {
            $request->session()->forget('state');

            return $this->failure($request->query('error') === 'access_denied' ? 'cancelled' : 'failed');
        }

        try {
            // Exchanges the code server-side and fetches authenticated Google
            // userinfo. Never trust profile data supplied by the browser.
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->failure('invalid_state');
        } catch (Throwable) {
            // Provider exceptions can contain codes/tokens. Do not expose them
            // in responses or application logs.
            $request->session()->forget('state');

            return $this->failure('failed');
        }

        $googleId = (string) $googleUser->getId();
        $email = Str::lower(trim((string) $googleUser->getEmail()));
        $verified = $googleUser->user['email_verified'] ?? $googleUser->user['verified_email'] ?? false;

        if ($googleId === '' || strlen($googleId) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 255 || ! in_array($verified, [true, 'true', 1, '1'], true)) {
            return $this->failure('unverified_email');
        }

        try {
            $user = DB::transaction(function () use ($googleId, $email, $googleUser): User {
                $linked = User::where('google_id', $googleId)->lockForUpdate()->first();
                if ($linked) {
                    // Changed Google emails must not switch local accounts.
                    return $linked;
                }

                $matches = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->limit(2)->get();
                if ($matches->count() > 1) {
                    throw ValidationException::withMessages(['email' => 'Account conflict.']);
                }
                if ($matches->isNotEmpty()) {
                    return $matches->first();
                }

                $user = new User;
                $user->forceFill([
                    'name' => Str::limit(trim((string) $googleUser->getName()) ?: Str::before($email, '@'), 255, ''),
                    'email' => $email,
                    'email_verified_at' => now(),
                    'google_id' => $googleId,
                    'password' => null,
                ])->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException|ValidationException) {
            return $this->failure('account_conflict');
        }

        if ($user->google_id !== $googleId) {
            if ($user->google_id !== null || ! $user->password) {
                return $this->failure('account_conflict');
            }

            // Matching emails only initiate a short-lived linking request.
            // The local account password is required before any association.
            $request->session()->put(self::PENDING_LINK, [
                'user_id' => $user->id,
                'google_id' => $googleId,
                'email' => $email,
                'expires_at' => now()->addMinutes(10)->timestamp,
                'attempts' => 0,
            ]);

            return $this->frontend('/?screen=google-link');
        }

        $this->signIn($request, $user);

        return $this->frontend('/dashboard');
    }

    public function link(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);
        $pending = $request->session()->get(self::PENDING_LINK);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) <= now()->timestamp || ($pending['attempts'] ?? 5) >= 5) {
            $request->session()->forget(self::PENDING_LINK);

            return response()->json(['message' => 'This Google linking request expired. Please start Google login again.'], 422);
        }

        $request->session()->put(self::PENDING_LINK.'.attempts', $pending['attempts'] + 1);

        try {
            $user = DB::transaction(function () use ($pending, $data): User {
                $user = User::whereKey($pending['user_id'])->lockForUpdate()->first();

                if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
                    throw ValidationException::withMessages(['password' => 'The existing account password is incorrect.']);
                }

                if (Str::lower($user->email) !== $pending['email'] || $user->google_id !== null
                    || User::where('google_id', $pending['google_id'])->exists()) {
                    throw ValidationException::withMessages(['password' => 'This account cannot be linked. Please start Google login again.']);
                }

                $user->forceFill([
                    'google_id' => $pending['google_id'],
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            $request->session()->forget(self::PENDING_LINK);

            return response()->json(['message' => 'This account is already linked. Please start Google login again.'], 409);
        }

        $this->signIn($request, $user);

        return response()->json(['message' => 'Google account linked successfully.', 'user' => $user]);
    }

    private function signIn(Request $request, User $user): void
    {
        $request->session()->forget([self::PENDING_LINK, 'login_otp']);
        Auth::guard('web')->login($user);
        $request->session()->put('auth_session_version', (int) $user->auth_session_version);
        $request->session()->regenerate();
        app(\App\Services\LoginSecurity::class)->record($request, $user, 'google');
    }

    private function failure(string $reason): RedirectResponse
    {
        return $this->frontend('/?screen=login&google_error='.$reason);
    }

    private function frontend(string $path): RedirectResponse
    {
        // Fixed server configuration, never a request-supplied return URL.
        return redirect()->away(config('auth.frontend_url').$path)
            ->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
