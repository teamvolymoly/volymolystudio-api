<?php

namespace Tests\Feature;

use App\Jobs\SendAuthMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuthMailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'queue.default' => 'database']);
        Mail::purge();
        $this->freezeTime();
    }

    private function messages()
    {
        return Mail::mailer()->getSymfonyTransport()->messages();
    }

    private function runQueuedMail(): void
    {
        $job = Queue::connection('database')->pop();
        $this->assertNotNull($job);
        $job->fire();
        $this->assertFalse($job->hasFailed());
    }

    private function resetToken(SendAuthMail $job): string
    {
        $body = (new \ReflectionProperty($job, 'body'))->getValue($job);
        preg_match('/https?:\/\/\S+/', $body, $match);
        parse_str(parse_url($match[0], PHP_URL_QUERY), $query);

        return $query['token'];
    }

    public function test_real_database_queue_encrypts_login_code_and_sends_only_latest_resend(): void
    {
        $user = User::factory()->create(['password' => 'OriginalPassword99!']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'OriginalPassword99!'])
            ->assertAccepted();
        $payload = json_decode(DB::table('jobs')->sole()->payload, true);
        $this->assertStringNotContainsString($user->email, $payload['data']['command']);
        $this->assertStringNotContainsString('Volymoly login code', $payload['data']['command']);

        $this->travel(61)->seconds();
        $this->postJson('/api/auth/login/resend')->assertAccepted()->assertJsonPath('retry_after', 60);
        $this->runQueuedMail(); // Superseded code must not reach the mail transport.
        $this->assertCount(0, $this->messages());
        $this->runQueuedMail();
        $this->assertCount(1, $this->messages());
        $message = $this->messages()->sole()->getOriginalMessage();
        $this->assertSame($user->email, $message->getTo()[0]->getAddress());
        preg_match('/\b\d{6}\b/', $message->getTextBody(), $match);
        $this->assertStringContainsString('Your verification code', $message->getHtmlBody());
        $this->assertStringContainsString('>'.$match[0].'</p>', $message->getHtmlBody());
        $this->assertStringContainsString('expires in 9 minutes', $message->getHtmlBody());
        $this->assertStringContainsString('expires in 9 minutes', $message->getTextBody());
        $this->postJson('/api/auth/login/verify', ['code' => $match[0]])->assertOk();
        $this->getJson('/api/auth/me')->assertOk();
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_password_login_sends_branded_email_with_a_working_code_and_embedded_logo(): void
    {
        $this->freezeTime();
        config(['auth.frontend_url' => 'https://studio.example.test']);
        $user = User::factory()->create(['password' => 'OriginalPassword99!']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'OriginalPassword99!'])
            ->assertAccepted()->assertJsonMissingPath('code');
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->runQueuedMail();

        $message = $this->messages()->sole()->getOriginalMessage();
        $this->assertSame($user->email, $message->getTo()[0]->getAddress());
        $this->assertSame('Your Volymoly login code', $message->getSubject());
        $this->assertSame(1, preg_match('/\b\d{6}\b/', $message->getTextBody(), $match));
        $this->assertTrue(Hash::check($match[0], EmailVerificationCode::sole()->code_hash));
        $html = $message->getHtmlBody();
        $this->assertStringContainsString('Your verification code', $html);
        $this->assertStringContainsString('>'.$match[0].'</p>', $html);
        $this->assertStringContainsString('expires in 10 minutes', $html);
        $this->assertStringContainsString('Do not share this verification code with anyone.', $html);
        $this->assertStringContainsString('href="https://studio.example.test"', $html);
        $this->assertStringContainsString('Indore 452001, India. All rights reserved.', $html);
        $this->assertStringNotContainsString('Reset password', $html);
        $this->assertStringContainsString('cid:', $html);
        $this->assertCount(1, $message->getAttachments());
        $logo = $message->getAttachments()[0];
        $this->assertSame('inline', $logo->getDisposition());
        $this->assertSame('image', $logo->getMediaType());
        $this->assertSame('png', $logo->getMediaSubtype());
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $logo->getBody());
        $this->postJson('/api/auth/login/verify', ['code' => $match[0]])->assertOk();
        $this->getJson('/api/auth/me')->assertOk();
    }

    public function test_delayed_login_email_preserves_leading_zeroes_and_displays_remaining_expiry(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $code = '012345';
        $verification = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email, 'purpose' => 'login',
            'code_hash' => Hash::make($code), 'attempts' => 0, 'max_attempts' => 5,
            'expires_at' => now()->addMinutes(10), 'last_sent_at' => now(),
        ]);
        SendAuthMail::dispatch($user->email, 'Your Volymoly login code', 'Legacy text', [
            'verification_id' => $verification->id, 'code_hash' => $verification->code_hash, 'code' => $code,
        ]);
        $this->travel(550)->seconds();
        $this->runQueuedMail();

        $message = $this->messages()->sole()->getOriginalMessage();
        $this->assertStringContainsString('>012345</p>', $message->getHtmlBody());
        $this->assertStringContainsString('012345', $message->getTextBody());
        $this->assertStringContainsString('expires in less than a minute', $message->getHtmlBody());
        $this->assertStringContainsString('expires in less than a minute', $message->getTextBody());
    }

    public function test_login_mail_queued_before_template_change_keeps_its_text_delivery(): void
    {
        $user = User::factory()->create();
        $code = '012345';
        $verification = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email, 'purpose' => 'login',
            'code_hash' => Hash::make($code), 'attempts' => 0, 'max_attempts' => 5,
            'expires_at' => now()->addMinutes(10), 'last_sent_at' => now(),
        ]);
        SendAuthMail::dispatch($user->email, 'Your Volymoly login code', 'Your login code is '.$code, [
            'verification_id' => $verification->id, 'code_hash' => $verification->code_hash,
        ]);
        $this->runQueuedMail();

        $message = $this->messages()->sole()->getOriginalMessage();
        $this->assertSame('Your login code is 012345', $message->getTextBody());
        $this->assertNull($message->getHtmlBody());
    }

    public function test_expired_login_code_is_not_sent_from_delayed_queue(): void
    {
        $user = User::factory()->create(['password' => 'OriginalPassword99!']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'OriginalPassword99!'])
            ->assertAccepted();
        $this->travel(10)->minutes();
        $this->runQueuedMail();
        $this->assertCount(0, $this->messages());
    }

    public function test_generic_verification_email_also_skips_superseded_codes(): void
    {
        $this->postJson('/api/auth/verification/send', ['email' => 'register@example.test'])->assertAccepted();
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/verification/send', ['email' => 'register@example.test'])->assertAccepted();
        $this->runQueuedMail();
        $this->assertCount(0, $this->messages());
        $this->runQueuedMail();
        $this->assertCount(1, $this->messages());
    }

    public function test_reset_link_resend_invalidates_old_link_and_new_link_is_single_use(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email' => 'reset@example.test']);
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertAccepted();
        $firstJob = Queue::pushed(SendAuthMail::class)->last();
        $firstToken = $this->resetToken($firstJob);
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('retry_after', 60);
        Queue::assertPushed(SendAuthMail::class, 1);
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertAccepted();
        $newJob = Queue::pushed(SendAuthMail::class)->last();
        $newToken = $this->resetToken($newJob);
        $this->assertFalse(Password::broker()->tokenExists($user, $firstToken));
        $this->assertTrue(Password::broker()->tokenExists($user, $newToken));
        $firstJob->handle();
        $this->assertCount(0, $this->messages());
        $newJob->handle();
        $this->assertCount(1, $this->messages());
        $this->assertStringContainsString('screen=reset-password', $this->messages()->sole()->getOriginalMessage()->getTextBody());

        $data = ['email' => $user->email, 'token' => $firstToken, 'password' => 'NewPassword99!', 'password_confirmation' => 'NewPassword99!'];
        $this->postJson('/api/auth/password/reset', $data)->assertUnprocessable()->assertJsonValidationErrors('token');
        $data['token'] = $newToken;
        $this->postJson('/api/auth/password/reset', $data)->assertOk();
        $this->postJson('/api/auth/password/reset', $data)->assertUnprocessable();
        $newJob->handle();
        $this->assertCount(1, $this->messages()); // A retried, consumed link is skipped.
    }

    public function test_queued_reset_email_contains_branded_html_embedded_logo_and_a_working_link(): void
    {
        config(['auth.frontend_url' => 'https://studio.example.test']);
        $user = User::factory()->create(['email' => 'reset+design@example.test']);
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertAccepted();
        $this->runQueuedMail();

        $message = $this->messages()->sole()->getOriginalMessage();
        $this->assertSame($user->email, $message->getTo()[0]->getAddress());
        $this->assertSame('Reset your Volymoly password', $message->getSubject());
        $html = $message->getHtmlBody();
        $this->assertStringContainsString('Reset your password', $html);
        $this->assertStringContainsString($user->email, $html);
        $this->assertStringContainsString('volymoly studio', $html);
        $this->assertStringContainsString('Indore 452001, India. All rights reserved.', $html);
        $this->assertStringContainsString('connected external login methods', $html);
        $this->assertStringNotContainsString('This link expires', $html);
        $this->assertStringNotContainsString('teamvolymoly@gmail.com', $html);

        preg_match('/href="([^"]+)"[^>]*>Reset password<\/a>/', $html, $match);
        $this->assertArrayHasKey(1, $match);
        $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
        $this->assertSame('studio.example.test', parse_url($url, PHP_URL_HOST));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('reset-password', $query['screen']);
        $this->assertSame($user->email, $query['email']);
        $this->assertTrue(Password::broker()->tokenExists($user, $query['token']));
        $this->assertStringContainsString($url, $message->getTextBody());
        $this->assertStringNotContainsString('&amp;', $message->getTextBody());
        $this->assertStringContainsString('ignore this email', $message->getTextBody());

        $this->assertCount(1, $message->getAttachments());
        $logo = $message->getAttachments()[0];
        $this->assertSame('inline', $logo->getDisposition());
        $this->assertSame('image', $logo->getMediaType());
        $this->assertSame('png', $logo->getMediaSubtype());
        $this->assertStringContainsString('cid:', $html);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $logo->getBody());

        $this->postJson('/api/auth/password/reset', [
            'email' => $query['email'],
            'token' => $query['token'],
            'password' => 'NewPassword99!',
            'password_confirmation' => 'NewPassword99!',
        ])->assertOk();
        $this->assertFalse(Password::broker()->tokenExists($user, $query['token']));
    }

    public function test_reset_mail_queued_before_template_change_still_renders(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        (new SendAuthMail($user->email, 'Reset your Volymoly password', 'Legacy text', ['reset_token' => $token]))->handle();

        $message = $this->messages()->sole()->getOriginalMessage();
        $this->assertStringContainsString('Reset your password', $message->getHtmlBody());
        $this->assertStringContainsString($user->passwordResetUrl($token), $message->getTextBody());
    }

    public function test_reset_email_escapes_recipient_html_and_uses_the_configured_expiry(): void
    {
        config(['auth.passwords.users.expire' => 30]);
        $user = User::factory()->create(['email' => 'reset&design@example.test']);
        $token = Password::broker()->createToken($user);
        $user->sendPasswordResetNotification($token);
        $this->runQueuedMail();

        $message = $this->messages()->sole()->getOriginalMessage();
        $this->assertStringContainsString('reset&amp;design@example.test', $message->getHtmlBody());
        $this->assertStringContainsString('expires 30 minutes', $message->getTextBody());
        $this->assertStringContainsString('reset&design@example.test', $message->getTextBody());
    }

    public function test_expired_reset_link_can_be_replaced_without_sending_the_old_email(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertAccepted();
        $oldJob = Queue::pushed(SendAuthMail::class)->last();
        $this->travel(61)->minutes();
        $oldJob->handle();
        $this->assertCount(0, $this->messages());
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertAccepted();
        Queue::pushed(SendAuthMail::class)->last()->handle();
        $this->assertCount(1, $this->messages());
    }

    public function test_unknown_email_has_same_reset_response_and_cooldown(): void
    {
        Queue::fake();
        $known = User::factory()->create();
        $expected = $this->postJson('/api/auth/password/forgot', ['email' => $known->email])->assertAccepted()->json();
        $this->postJson('/api/auth/password/forgot', ['email' => 'unknown@example.test'])
            ->assertAccepted()->assertExactJson($expected);
        $this->postJson('/api/auth/password/forgot', ['email' => 'unknown@example.test'])
            ->assertStatus(429)->assertHeader('Retry-After');
        Queue::assertPushed(SendAuthMail::class, 1);
    }

    public function test_resend_cooldown_returns_remaining_seconds(): void
    {
        Queue::fake();
        $user = User::factory()->create(['password' => 'OriginalPassword99!']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'OriginalPassword99!'])->assertAccepted();
        $this->travel(20)->seconds();
        $this->postJson('/api/auth/login/resend')->assertStatus(429)
            ->assertHeader('Retry-After', '40')->assertJsonPath('retry_after', 40);
    }

    public function test_smtp_failure_is_not_swallowed_so_queue_can_retry(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('test transport failure'));
        $this->expectException(\RuntimeException::class);
        (new SendAuthMail('test@example.test', 'Test', 'Test'))->handle();
    }
}
