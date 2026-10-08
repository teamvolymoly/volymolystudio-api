<?php

namespace Tests\Feature;

use App\Jobs\SendAuthMail;
use App\Jobs\SendNewDeviceAlert;
use App\Models\LoginActivity;
use App\Models\RecognizedLoginDevice;
use App\Models\User;
use App\Services\LoginClientContext;
use App\Services\LoginSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NewDeviceAlertTest extends TestCase
{
    use RefreshDatabase;

    private const AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/152.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        config(['login_security.enabled' => true, 'login_security.review_path' => '/security/activity',
            'auth.frontend_url' => 'https://studio.example.test', 'queue.default' => 'database', 'mail.default' => 'array']);
        Mail::purge();
        $this->freezeTime();
    }

    private function completeLogin(User $user, string $device): void
    {
        $this->withCredentials()->withCookie('volymoly_device', $device)->withHeader('User-Agent', self::AGENT);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'OriginalPassword99!'])->assertAccepted();
        $otp = Queue::pushed(SendAuthMail::class)->last();
        $body = (new \ReflectionProperty($otp, 'body'))->getValue($otp);
        preg_match('/\b\d{6}\b/', $body, $match);
        $this->postJson('/api/auth/login/verify', ['code' => $match[0]])->assertOk();
    }

    public function test_only_successful_login_registers_device_and_queues_alert(): void
    {
        Queue::fake();
        $user = User::factory()->create(['password' => 'OriginalPassword99!']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'incorrect'])->assertUnauthorized();
        Queue::assertNotPushed(SendNewDeviceAlert::class);
        $this->assertDatabaseCount('recognized_login_devices', 0);
        $this->completeLogin($user, str_repeat('a', 64));
        Queue::assertPushed(SendNewDeviceAlert::class, 1);
        $this->assertDatabaseCount('login_activities', 1);
        $this->assertSame(hash('sha256', str_repeat('a', 64)), RecognizedLoginDevice::sole()->token_hash);
        $this->assertSame('password', LoginActivity::sole()->login_method);
        $this->assertSame('Chrome 152 on Windows 10 or later', LoginActivity::sole()->device);
    }

    public function test_same_browser_after_logout_does_not_alert_but_a_new_cookie_does(): void
    {
        Queue::fake();
        $user = User::factory()->create(['password' => 'OriginalPassword99!']);
        $this->completeLogin($user, str_repeat('a', 64));
        $this->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->travel(61)->seconds();
        $this->completeLogin($user, str_repeat('a', 64));
        Queue::assertPushed(SendNewDeviceAlert::class, 1);
        $this->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->travel(61)->seconds();
        $this->completeLogin($user, str_repeat('b', 64));
        Queue::assertPushed(SendNewDeviceAlert::class, 2);
        $this->assertDatabaseCount('recognized_login_devices', 2);
    }

    public function test_browser_recognition_is_per_account_and_browser_versions_do_not_identify_devices(): void
    {
        Queue::fake();
        $request = Request::create('/api/auth/login/verify', 'POST', server: ['HTTP_USER_AGENT' => self::AGENT]);
        $request->attributes->set('login_browser_token', str_repeat('a', 64));
        $first = User::factory()->create();
        $second = User::factory()->create();
        $security = app(LoginSecurity::class);
        $security->record($request, $first, 'password');
        $request->headers->set('User-Agent', str_replace('152', '153', self::AGENT));
        $security->record($request, $first, 'google');
        $security->record($request, $second, 'google');
        Queue::assertPushed(SendNewDeviceAlert::class, 2);
        $this->assertDatabaseCount('recognized_login_devices', 2);
    }

    public function test_alert_html_uses_real_fields_and_review_link_is_private_expiring_and_read_only(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email' => 'alert@example.test', 'password' => 'OriginalPassword99!']);
        $this->completeLogin($user, str_repeat('a', 64));
        $job = Queue::pushed(SendNewDeviceAlert::class)->sole();
        $job->handle();
        $message = Mail::mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $html = $message->getHtmlBody();
        $this->assertStringContainsString('New device signed in', $html);
        $this->assertStringContainsString('alert@example.test', $html);
        $this->assertStringContainsString('Chrome 152 on Windows 10 or later', $html);
        $this->assertStringContainsString('Location: Unavailable', $html);
        $this->assertStringNotContainsString('122.181.101.138', $html);
        $this->assertCount(1, $message->getAttachments());
        $this->assertSame('inline', $message->getAttachments()[0]->getDisposition());
        preg_match('/href="([^"]+)"[^>]*>Review activity<\/a>/', $html, $match);
        $url = html_entity_decode($match[1]);
        $this->assertStringStartsWith('https://studio.example.test/security/activity?token=', $url);
        $this->assertStringContainsString($url, $message->getTextBody());
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(hash('sha256', $query['token']), LoginActivity::sole()->review_token_hash);
        $this->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/auth/security/activity?token='.$query['token'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('activity.email', $user->email)
            ->assertJsonMissingPath('activity.review_token_hash');
        $this->assertGuest('web');
        $this->getJson('/api/auth/security/activity?token='.str_repeat('f', 64))->assertGone();
        $job->handle();
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
        $this->travel(24)->hours();
        $this->getJson('/api/auth/security/activity?token='.$query['token'])->assertGone();
    }

    public function test_real_queue_encrypts_review_token_and_skips_expired_delayed_alert(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/api/auth/google/callback');
        $request->attributes->set('login_browser_token', str_repeat('a', 64));
        app(LoginSecurity::class)->record($request, $user, 'google');
        $payload = json_decode(DB::table('jobs')->sole()->payload, true);
        $this->assertStringNotContainsString('reviewToken', $payload['data']['command']);
        $this->assertStringNotContainsString($user->email, $payload['data']['command']);
        $this->travel(24)->hours();
        $job = Queue::connection('database')->pop();
        $this->assertNotNull($job);
        $job->fire();
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_signed_context_is_required_for_forwarded_ip_and_country(): void
    {
        config(['login_security.proxy_secret' => str_repeat('test-key', 8)]);
        $context = base64_encode(json_encode(['timestamp' => now()->timestamp, 'agent' => self::AGENT,
            'ip' => '203.0.113.9', 'location' => 'India']));
        $request = Request::create('/api/auth/login/verify', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $request->headers->set('x-auth-client-context', $context);
        $reader = app(LoginClientContext::class);
        $this->assertNull($reader->read($request)['ip_address']);
        $this->assertNull($reader->read($request)['location']);
        $signature = hash_hmac('sha256', "POST\n/api/auth/login/verify\n".$context, config('login_security.proxy_secret'));
        $request->headers->set('x-auth-client-signature', $signature);
        $this->assertSame('203.0.113.9', $reader->read($request)['ip_address']);
        $this->assertSame('India', $reader->read($request)['location']);
        $this->travel(61)->seconds();
        $this->assertNull($reader->read($request)['ip_address']);
    }

    public function test_device_cookie_is_encrypted_httponly_and_secure_in_production(): void
    {
        config(['session.secure' => true]);
        $response = $this->getJson('/api/auth/csrf-token')->assertOk()->assertCookie('volymoly_device');
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'volymoly_device');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertDoesNotMatchRegularExpression('/\A[a-f0-9]{64}\z/', $cookie->getValue());
    }

    public function test_alerts_remain_off_until_an_approved_review_page_is_configured(): void
    {
        Queue::fake();
        config(['login_security.review_path' => null]);
        $user = User::factory()->create(['password' => 'OriginalPassword99!']);
        $this->completeLogin($user, str_repeat('a', 64));
        Queue::assertNotPushed(SendNewDeviceAlert::class);
        $this->assertDatabaseCount('login_activities', 0);
    }
}
