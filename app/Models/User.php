<?php

namespace App\Models;

use App\Jobs\SendAuthMail;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements CanResetPasswordContract
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use CanResetPassword, HasApiTokens, HasFactory, Notifiable;

    public function passwordResetUrl(string $token): string
    {
        return rtrim(config('auth.frontend_url'), '/')
            . '/?screen=reset-password&token='
            . urlencode($token)
            . '&email='
            . urlencode($this->getEmailForPasswordReset());
    }

    public function sendPasswordResetNotification($token): void
    {
        $resetUrl = $this->passwordResetUrl($token);

        SendAuthMail::dispatch(
            $this->getEmailForPasswordReset(),
            'Reset your Volymoly password',
            "Use this link to reset your Volymoly password:\n\n{$resetUrl}\n\nOnly the latest reset link can be used. If it has expired, request a new link from the login page.",
            ['reset_token' => $token, 'reset_url' => $resetUrl],
        );
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'google_id',
        'auth_session_version',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'auth_session_version' => 'integer',
        ];
    }
}
