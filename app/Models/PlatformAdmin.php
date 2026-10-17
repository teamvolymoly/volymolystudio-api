<?php

namespace App\Models;

use App\Jobs\SendPlatformAdminPasswordResetMail;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class PlatformAdmin extends Authenticatable implements CanResetPasswordContract
{
    use CanResetPassword, HasFactory, Notifiable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public function passwordResetUrl(string $token): string
    {
        return rtrim(config('auth.platform_frontend_url'), '/')
            .'/reset-password?email='
            .urlencode($this->getEmailForPasswordReset())
            .'#token='
            .urlencode($token);
    }

    public function sendPasswordResetNotification($token): void
    {
        $resetUrl = $this->passwordResetUrl($token);

        SendPlatformAdminPasswordResetMail::dispatch(
            $this->getEmailForPasswordReset(),
            $token,
            $resetUrl,
        );
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(PlatformAdminAuditLog::class);
    }

    public function loginCodes(): HasMany
    {
        return $this->hasMany(PlatformAdminLoginCode::class);
    }

    public function createdOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'created_by_platform_admin_id');
    }

    public function createdStores(): HasMany
    {
        return $this->hasMany(Store::class, 'created_by_platform_admin_id');
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'must_change_password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'auth_session_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'auth_session_version' => 'integer',
        ];
    }
}
