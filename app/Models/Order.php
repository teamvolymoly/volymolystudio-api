<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_PARTIALLY_REFUNDED = 'partially_refunded';

    public const PAYMENT_REFUNDED = 'refunded';

    public const PAYMENT_FAILED = 'failed';

    public const FULFILLMENT_UNFULFILLED = 'unfulfilled';

    public const FULFILLMENT_PARTIAL = 'partial';

    public const FULFILLMENT_FULFILLED = 'fulfilled';

    public const FULFILLMENT_RETURNED = 'returned';

    protected $fillable = [
        'store_id',
        'order_number',
        'channel',
        'status',
        'payment_status',
        'fulfillment_status',
        'customer_name',
        'customer_email',
        'customer_phone',
        'items_count',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'shipping_amount',
        'total_amount',
        'currency',
        'placed_at',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_CONFIRMED,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ];
    }

    public static function paymentStatuses(): array
    {
        return [
            self::PAYMENT_PENDING,
            self::PAYMENT_PAID,
            self::PAYMENT_PARTIALLY_REFUNDED,
            self::PAYMENT_REFUNDED,
            self::PAYMENT_FAILED,
        ];
    }

    public static function fulfillmentStatuses(): array
    {
        return [
            self::FULFILLMENT_UNFULFILLED,
            self::FULFILLMENT_PARTIAL,
            self::FULFILLMENT_FULFILLED,
            self::FULFILLMENT_RETURNED,
        ];
    }

    /**
     * Return valid next values for each state machine. Current values are
     * accepted separately so saving an unchanged form stays idempotent.
     *
     * @return array{
     *     status: list<string>,
     *     payment_status: list<string>,
     *     fulfillment_status: list<string>
     * }
     */
    public function allowedTransitions(): array
    {
        return [
            'status' => match ($this->status) {
                self::STATUS_PENDING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
                self::STATUS_CONFIRMED => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
                self::STATUS_PROCESSING => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
                default => [],
            },
            'payment_status' => match ($this->payment_status) {
                self::PAYMENT_PENDING => [self::PAYMENT_PAID, self::PAYMENT_FAILED],
                self::PAYMENT_FAILED => [self::PAYMENT_PENDING, self::PAYMENT_PAID],
                self::PAYMENT_PAID => [self::PAYMENT_PARTIALLY_REFUNDED, self::PAYMENT_REFUNDED],
                self::PAYMENT_PARTIALLY_REFUNDED => [self::PAYMENT_REFUNDED],
                default => [],
            },
            'fulfillment_status' => match ($this->fulfillment_status) {
                self::FULFILLMENT_UNFULFILLED => [self::FULFILLMENT_PARTIAL, self::FULFILLMENT_FULFILLED],
                self::FULFILLMENT_PARTIAL => [self::FULFILLMENT_FULFILLED, self::FULFILLMENT_RETURNED],
                self::FULFILLMENT_FULFILLED => [self::FULFILLMENT_RETURNED],
                default => [],
            },
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
            'items_count' => 'integer',
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'placed_at' => 'datetime',
        ];
    }
}
