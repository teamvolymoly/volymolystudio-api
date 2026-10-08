<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthDataPruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthDataRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pruner_removes_only_auth_records_outside_the_retention_windows(): void
    {
        config([
            'auth_retention.verification_days' => 1,
            'auth_retention.recovery_days' => 1,
            'auth_retention.activity_days' => 1,
            'auth_retention.device_days' => 1,
        ]);
        $user = User::factory()->create();
        $old = now()->subDays(2);
        $recent = now();

        DB::table('email_verification_codes')->insert([
            ['email' => 'old@example.test', 'purpose' => 'registration', 'code_hash' => 'old',
                'attempts' => 0, 'max_attempts' => 5, 'expires_at' => $old, 'last_sent_at' => $old,
                'created_at' => $old, 'updated_at' => $old],
            ['email' => 'recent@example.test', 'purpose' => 'registration', 'code_hash' => 'recent',
                'attempts' => 0, 'max_attempts' => 5, 'expires_at' => $recent->copy()->addMinutes(10),
                'last_sent_at' => $recent, 'created_at' => $recent, 'updated_at' => $recent],
        ]);
        DB::table('account_recovery_requests')->insert([
            ['request_id' => (string) Str::uuid(), 'new_email' => 'old-recovery@example.test',
                'status' => 'pending', 'expires_at' => $old, 'created_at' => $old, 'updated_at' => $old],
            ['request_id' => (string) Str::uuid(), 'new_email' => 'recent-recovery@example.test',
                'status' => 'pending', 'expires_at' => $recent->copy()->addMinutes(30),
                'created_at' => $recent, 'updated_at' => $recent],
        ]);
        $oldDevice = DB::table('recognized_login_devices')->insertGetId([
            'user_id' => $user->id, 'token_hash' => hash('sha256', 'old-device'),
            'last_seen_at' => $old, 'created_at' => $old, 'updated_at' => $old,
        ]);
        $recentDevice = DB::table('recognized_login_devices')->insertGetId([
            'user_id' => $user->id, 'token_hash' => hash('sha256', 'recent-device'),
            'last_seen_at' => $recent, 'created_at' => $recent, 'updated_at' => $recent,
        ]);
        DB::table('login_activities')->insert([
            ['user_id' => $user->id, 'recognized_login_device_id' => $oldDevice, 'email' => $user->email,
                'device' => 'Old browser', 'login_method' => 'password',
                'review_token_hash' => hash('sha256', 'old-review'), 'review_expires_at' => $old,
                'created_at' => $old, 'updated_at' => $old],
            ['user_id' => $user->id, 'recognized_login_device_id' => $recentDevice, 'email' => $user->email,
                'device' => 'Current browser', 'login_method' => 'password',
                'review_token_hash' => hash('sha256', 'recent-review'),
                'review_expires_at' => $recent->copy()->addDay(),
                'created_at' => $recent, 'updated_at' => $recent],
        ]);

        $counts = app(AuthDataPruner::class)->prune();

        $this->assertSame(['verifications' => 1, 'recoveries' => 1, 'activities' => 1, 'devices' => 1], $counts);
        $this->assertDatabaseMissing('email_verification_codes', ['email' => 'old@example.test']);
        $this->assertDatabaseHas('email_verification_codes', ['email' => 'recent@example.test']);
        $this->assertDatabaseMissing('account_recovery_requests', ['new_email' => 'old-recovery@example.test']);
        $this->assertDatabaseHas('account_recovery_requests', ['new_email' => 'recent-recovery@example.test']);
        $this->assertDatabaseMissing('login_activities', ['device' => 'Old browser']);
        $this->assertDatabaseHas('login_activities', ['device' => 'Current browser']);
        $this->assertDatabaseMissing('recognized_login_devices', ['id' => $oldDevice]);
        $this->assertDatabaseHas('recognized_login_devices', ['id' => $recentDevice]);
    }
}
