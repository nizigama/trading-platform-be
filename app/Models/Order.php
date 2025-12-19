<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    /**
     * Order side constants.
     */
    public const SIDE_BUY = 1;
    public const SIDE_SELL = 2;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'symbol_id',
        'side',
        'price',
        'amount',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'side' => 'integer',
            'price' => 'decimal:18',
            'amount' => 'decimal:18',
            'status' => OrderStatus::class,
        ];
    }

    /**
     * Get the user that placed the order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the symbol for this order.
     */
    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    /**
     * Get the trades for this order (as buy order).
     */
    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    /**
     * Get the trade for this order (as sell order).
     */
    public function sellTrade(): HasOne
    {
        return $this->hasOne(Trade::class, 'sell_order_id');
    }

    /**
     * Get the commission for a sell order.
     */
    public function getSellCommissionAttribute(): ?string
    {
        if ($this->side !== self::SIDE_SELL) {
            return null;
        }

        return $this->sellTrade?->commission;
    }

    /**
     * Get the execution price for a sell order.
     * This may differ from the order price when the trade executes at the buyer's price.
     */
    public function getSellExecutionPriceAttribute(): ?string
    {
        if ($this->side !== self::SIDE_SELL) {
            return null;
        }

        return $this->sellTrade?->price;
    }
}

