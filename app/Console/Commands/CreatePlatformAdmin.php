<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform-admin:create';

    protected $description = 'Create a Platform Super Admin account securely';

    public function handle(): int
    {
        $name = trim((string) $this->ask('Name'));
        $email = Str::lower(trim((string) $this->ask('Email address')));
        $password = (string) $this->secret('Password (hidden)');
        $confirmation = (string) $this->secret('Confirm password (hidden)');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:platform_admins,email'],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->mixedCase()->letters()->numbers()->symbols(),
            ],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        PlatformAdmin::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'status' => PlatformAdmin::STATUS_ACTIVE,
        ])->forceFill([
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ])->save();

        $this->info('Platform Super Admin created successfully.');

        return self::SUCCESS;
    }
}
