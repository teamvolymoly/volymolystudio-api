<?php

namespace Tests\Feature;

use App\Jobs\SendAuthMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_session_and_logout(): void
    {
        Queue::fake();
        User::factory()->create([
            'email' => 'login@example.test',
            'password' => Hash::make('OriginalPassword99!'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'login@example.test',
            'password' => 'OriginalPassword99!',
        ])->assertAccepted()->assertJsonPath('otp_required', true);

        $this->getJson('/api/auth/me')->assertUnauthorized();
        $job = Queue::pushed(SendAuthMail::class)->last();
        $body = (new \ReflectionProperty($job, 'body'))->getValue($job);
        preg_match('/\b\d{6}\b/', $body, $matches);
        $this->postJson('/api/auth/login/verify', ['code' => $matches[0]])->assertOk();
        $this->getJson('/api/auth/me')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_reset_link_is_queued_and_token_resets_password(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'email' => 'reset@example.test',
            'password' => Hash::make('OriginalPassword99!'),
        ]);

        $this->postJson('/api/auth/password/forgot', [
            'email' => $user->email,
        ])->assertAccepted();

        Queue::assertPushed(SendAuthMail::class, function (SendAuthMail $job): bool {
            $body = (new \ReflectionProperty($job, 'body'))->getValue($job);

            return str_contains($body, 'http://localhost:3000/?screen=reset-password')
                && ! str_contains($body, ',http://127.0.0.1:3000');
        });

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPassword99!',
            'password_confirmation' => 'NewPassword99!',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'NewPassword99!',
        ])->assertAccepted()->assertJsonPath('otp_required', true);
        $this->assertGuest('web');
    }

    public function test_verification_code_is_queued_and_can_be_verified(): void
    {
        Queue::fake();

        $this->postJson('/api/auth/verification/send', [
            'email' => 'verify@example.test',
            'purpose' => 'registration',
        ])->assertAccepted();

        Queue::assertPushed(SendAuthMail::class, 1);
        $job = Queue::pushed(SendAuthMail::class)->first();
        $body = (new \ReflectionProperty($job, 'body'))->getValue($job);
        $this->assertSame(1, preg_match('/\b\d{6}\b/', $body, $matches));

        $this->postJson('/api/auth/verification/verify', [
            'email' => 'verify@example.test',
            'purpose' => 'registration',
            'code' => $matches[0],
        ])->assertOk()->assertJsonPath('verified', true);
    }
}
