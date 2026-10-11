<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreatePlatformAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_a_verified_admin_with_a_hashed_password(): void
    {
        $this->artisan('platform-admin:create')
            ->expectsQuestion('Name', 'Primary Owner')
            ->expectsQuestion('Email address', 'OWNER@EXAMPLE.TEST')
            ->expectsQuestion('Password (hidden)', 'Strong!Password123')
            ->expectsQuestion('Confirm password (hidden)', 'Strong!Password123')
            ->expectsOutput('Platform Super Admin created successfully.')
            ->assertSuccessful();

        $admin = PlatformAdmin::where('email', 'owner@example.test')->firstOrFail();

        $this->assertTrue(Hash::check('Strong!Password123', $admin->password));
        $this->assertNotSame('Strong!Password123', $admin->password);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertNotNull($admin->password_changed_at);
    }

    public function test_command_rejects_duplicate_email(): void
    {
        PlatformAdmin::create([
            'name' => 'Existing Owner',
            'email' => 'owner@example.test',
            'password' => 'Strong!Password123',
        ]);

        $this->artisan('platform-admin:create')
            ->expectsQuestion('Name', 'Another Owner')
            ->expectsQuestion('Email address', 'owner@example.test')
            ->expectsQuestion('Password (hidden)', 'Another!Password123')
            ->expectsQuestion('Confirm password (hidden)', 'Another!Password123')
            ->expectsOutput('The email has already been taken.')
            ->assertFailed();

        $this->assertDatabaseCount('platform_admins', 1);
    }
}
