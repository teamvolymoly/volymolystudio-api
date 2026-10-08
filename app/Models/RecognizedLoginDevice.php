<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecognizedLoginDevice extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'review_token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
