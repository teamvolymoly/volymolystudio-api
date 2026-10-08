<?php

namespace Tests\Feature;

use App\Jobs\SendAuthMail;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private array $httpHistory = [];

    private Client $googleClient;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost:3000/api/auth/google/callback',
            'auth.frontend_url' => 'http://localhost:3000',
        ]);

        $this->googleProfile();
    }

    private function googleProfile(array $overrides = [], int $tokenStatus = 200): void
    {
        $this->httpHistory = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response($tokenStatus, [], json_encode(['access_token' => 'test-google-access-token', 'token_type' => 'Bearer', 'expires_in' => 3600])),
            new Response(200, [], json_encode(array_merge([
                'sub' => 'google-user-123',
                'email' => 'google@example.test',
                'email_verified' => true,
                'name' => 'Google User',
            ], $overrides))),
        ]));
        $handler->push(Middleware::history($this->httpHistory));
        $this->googleClient = new Client(['handler' => $handler]);

        // Use real Socialite state checks and Google profile mapping; mock only
        // Google's HTTP responses. A fresh provider gets each current request.
        Socialite::shouldReceive('driver')->with('google')->andReturnUsing(
            fn () => (new GoogleProvider(
                request(),
                config('services.google.client_id'),
                config('services.google.client_secret'),
                config('services.google.redirect'),
            ))->setHttpClient($this->googleClient)
        );
    }

    private function startGoogleLogin(): string
    {
        $response = $this->get('/api/auth/google/redirect')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['state']);
        $this->assertSame(config('services.google.redirect'), $query['redirect_uri']);
        $response->assertSessionHas('state', $query['state']);

        return $query['state'];
    }

    private function completeGoogleLogin(?string $state = null): TestResponse
    {
        $state ??= $this->startGoogleLogin();

        return $this->get('/api/auth/google/callback?'.http_build_query([
            'code' => 'test-code',
            'state' => $state,
        ]));
    }

    public function test_google_login_and_password_confirmed_linking_register_new_devices(): void
    {
        config(['login_security.enabled' => true, 'login_security.review_path' => '/security/activity']);
        Queue::fake();
        $this->withCredentials()->withCookie('volymoly_device', str_repeat('a', 64));
        $this->completeGoogleLogin()->assertRedirect('http://localhost:3000/dashboard');
        Queue::assertPushed(\App\Jobs\SendNewDeviceAlert::class, 1);
        $this->assertSame('google', \App\Models\LoginActivity::sole()->login_method);
        $this->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->travel(61)->seconds();
        $this->googleProfile(['sub' => 'another-google-id', 'email' => 'existing-link@example.test']);
        $user = User::factory()->create(['email' => 'existing-link@example.test', 'password' => 'OriginalPassword99!']);
        $this->completeGoogleLogin()->assertRedirect('http://localhost:3000/?screen=account-exists-google');
        $this->getJson('/api/auth/google/link-context')
            ->assertOk()->assertJsonPath('email', 'existing-link@example.test');
        Queue::assertPushed(\App\Jobs\SendNewDeviceAlert::class, 1);
        $this->postJson('/api/auth/google/link', ['password' => 'wrong'])->assertUnprocessable();
        Queue::assertPushed(\App\Jobs\SendNewDeviceAlert::class, 1);
        $this->postJson('/api/auth/google/link', ['password' => 'OriginalPassword99!'])->assertOk();
        Queue::assertPushed(\App\Jobs\SendNewDeviceAlert::class, 2);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_new_google_user_gets_existing_session_me_and_logout_flow(): void
    {
        $state = $this->startGoogleLogin();
        $oldSessionId = session()->getId();
        $this->completeGoogleLogin($state)->assertRedirect('http://localhost:3000/dashboard');

        $user = User::where('google_id', 'google-user-123')->sole();
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.google_id');
        $this->assertCount(2, $this->httpHistory);
        $this->assertStringContainsString('client_secret=test-client-secret', (string) $this->httpHistory[0]['request']->getBody());
        $this->assertSame('Bearer test-google-access-token', $this->httpHistory[1]['request']->getHeaderLine('Authorization'));

        $this->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'not-a-password'])->assertUnauthorized();
    }

    public function test_matching_email_requires_password_confirmation_and_preserves_password(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email' => 'Google@Example.test', 'password' => 'OriginalPassword99!', 'email_verified_at' => null]);
        $originalHash = $user->password;

        $this->completeGoogleLogin()->assertRedirect('http://localhost:3000/?screen=account-exists-google')
            ->assertSessionHas('google_oauth_link.user_id', $user->id);
        $this->getJson('/api/auth/google/link-context')
            ->assertOk()->assertJsonPath('email', 'google@example.test');
        $this->assertGuest('web');
        $this->assertNull($user->fresh()->google_id);
        $this->assertDatabaseCount('users', 1);

        $this->postJson('/api/auth/google/link', ['password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertNull($user->fresh()->google_id);
        $this->postJson('/api/auth/google/link', ['password' => 'OriginalPassword99!'])
            ->assertOk()->assertSessionMissing('google_oauth_link');
        $this->getJson('/api/auth/google/link-context')->assertUnprocessable();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame('google-user-123', $user->fresh()->google_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/auth/google/link', ['password' => 'OriginalPassword99!'])->assertUnprocessable();
        $this->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'OriginalPassword99!'])
            ->assertAccepted()->assertJsonPath('otp_required', true);
        $this->assertGuest('web');
    }

    public function test_returning_user_is_identified_by_google_id_even_when_google_email_changes(): void
    {
        $owner = User::factory()->create(['email' => 'old@example.test', 'google_id' => 'google-user-123']);
        $other = User::factory()->create(['email' => 'google@example.test']);

        $this->completeGoogleLogin()->assertRedirect('http://localhost:3000/dashboard');

        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertSame('old@example.test', $owner->fresh()->email);
        $this->assertNull($other->fresh()->google_id);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_missing_or_tampered_state_cannot_exchange_code_or_create_user(): void
    {
        $this->completeGoogleLogin('unsolicited-state')->assertRedirect('http://localhost:3000/?screen=login&google_error=invalid_state');
        $this->startGoogleLogin();
        $this->completeGoogleLogin('tampered-state')->assertRedirect('http://localhost:3000/?screen=login&google_error=invalid_state');
        $this->assertCount(0, $this->httpHistory);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public function test_callback_state_cannot_be_replayed(): void
    {
        $state = $this->startGoogleLogin();
        $this->completeGoogleLogin($state)->assertRedirect('http://localhost:3000/dashboard');
        $this->completeGoogleLogin($state)->assertRedirect('http://localhost:3000/?screen=login&google_error=invalid_state');
        $this->assertCount(2, $this->httpHistory);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_cancelled_login_clears_state_and_does_not_contact_google(): void
    {
        $state = $this->startGoogleLogin();
        $this->get('/api/auth/google/callback?'.http_build_query(['state' => $state, 'error' => 'access_denied']))
            ->assertRedirect('http://localhost:3000/?screen=login&google_error=cancelled')
            ->assertSessionMissing('state');
        $this->assertGuest('web');
        $this->assertCount(0, $this->httpHistory);
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $this->googleProfile(['email_verified' => false]);
        $this->completeGoogleLogin()->assertRedirect('http://localhost:3000/?screen=login&google_error=unverified_email');
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_failure_returns_only_a_safe_error(): void
    {
        $this->googleProfile([], 500);
        $this->completeGoogleLogin()->assertRedirect('http://localhost:3000/?screen=login&google_error=failed');
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_another_google_identity_cannot_replace_an_existing_link(): void
    {
        $user = User::factory()->create(['email' => 'google@example.test', 'google_id' => 'another-google-id']);
        $this->completeGoogleLogin()->assertRedirect('http://localhost:3000/?screen=login&google_error=account_conflict')
            ->assertSessionMissing('google_oauth_link');
        $this->assertSame('another-google-id', $user->fresh()->google_id);
        $this->assertGuest('web');
    }

    public function test_expired_link_cannot_be_confirmed(): void
    {
        $user = User::factory()->create(['email' => 'google@example.test', 'password' => 'OriginalPassword99!']);
        $this->completeGoogleLogin();
        $this->travel(11)->minutes();
        $this->postJson('/api/auth/google/link', ['password' => 'OriginalPassword99!'])
            ->assertUnprocessable()->assertSessionMissing('google_oauth_link');
        $this->assertNull($user->fresh()->google_id);
        $this->assertGuest('web');
    }

    public function test_linking_attempts_are_limited_even_across_throttle_windows(): void
    {
        $user = User::factory()->create(['email' => 'google@example.test', 'password' => 'OriginalPassword99!']);
        $this->completeGoogleLogin();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/google/link', ['password' => 'wrong'])->assertUnprocessable();
            $this->travel(61)->seconds();
        }
        $this->postJson('/api/auth/google/link', ['password' => 'OriginalPassword99!'])
            ->assertUnprocessable()->assertSessionMissing('google_oauth_link');
        $this->assertNull($user->fresh()->google_id);
        $this->assertGuest('web');
    }

    public function test_password_confirmation_cannot_link_a_different_browser_supplied_identity(): void
    {
        $user = User::factory()->create(['email' => 'google@example.test', 'password' => 'OriginalPassword99!']);
        $this->completeGoogleLogin();
        $this->postJson('/api/auth/google/link', ['password' => 'OriginalPassword99!', 'google_id' => 'forged-id', 'email' => 'attacker@example.test'])
            ->assertOk();
        $this->assertSame('google-user-123', $user->fresh()->google_id);
    }

    public function test_link_requires_csrf_protection(): void
    {
        $this->app->instance('env', 'local');
        $this->postJson('/api/auth/google/link', ['password' => 'anything'])->assertStatus(419);
    }

    public function test_unconfigured_google_returns_useful_error_without_leaking_settings(): void
    {
        config(['services.google.client_secret' => null]);
        $this->get('/api/auth/google/redirect')
            ->assertRedirect('http://localhost:3000/?screen=login&google_error=not_configured');
        $this->assertCount(0, $this->httpHistory);
    }

    public function test_google_only_user_can_set_password_through_existing_reset_flow(): void
    {
        $this->completeGoogleLogin();
        $user = User::where('google_id', 'google-user-123')->sole();
        $token = Password::broker()->createToken($user);
        $this->postJson('/api/auth/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPassword99!',
            'password_confirmation' => 'NewPassword99!',
        ])->assertOk();
        $this->assertTrue(Hash::check('NewPassword99!', $user->fresh()->password));
        $this->assertSame('google-user-123', $user->fresh()->google_id);
        Auth::forgetGuards();
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->googleProfile();
        $this->completeGoogleLogin()->assertRedirect('http://localhost:3000/dashboard')
            ->assertSessionHas('auth_session_version', 1);
        Auth::forgetGuards();
        $this->getJson('/api/auth/me')->assertOk();
    }

    public function test_password_reset_uses_the_configured_frontend_after_config_caching(): void
    {
        config(['auth.frontend_url' => 'https://frontend.example.test']);
        Queue::fake();
        $user = User::factory()->create(['email' => 'reset@example.test']);
        $user->sendPasswordResetNotification('test-reset-token');

        Queue::assertPushed(SendAuthMail::class, function ($job): bool {
            $body = (new \ReflectionProperty($job, 'body'))->getValue($job);

            return str_contains($body, 'https://frontend.example.test/?screen=reset-password')
                && ! str_contains($body, 'localhost');
        });
    }
}
