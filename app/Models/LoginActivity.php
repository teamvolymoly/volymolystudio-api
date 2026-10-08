<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginActivity extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'review_token_hash'];

    protected function casts(): array
    {
        return ['review_expires_at' => 'datetime', 'alert_sent_at' => 'datetime'];
    }
}
