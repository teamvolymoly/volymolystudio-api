<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_UNLISTED = 'unlisted';

    protected $fillable = [
        'store_id',
        'name',
        'status',
        'inventory',
        'category',
        'channels',
        'product_type',
        'vendor',
    ];

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_DRAFT,
            self::STATUS_UNLISTED,
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'inventory' => 'integer',
            'channels' => 'integer',
        ];
    }
}
