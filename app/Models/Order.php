<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Get the commission for a sell order by finding the trade where user is seller.
     */
    public function getSellCommissionAttribute(): ?string
    {
        if ($this->side !== self::SIDE_SELL) {
            return null;
        }

        return Trade::where('seller_id', $this->user_id)
            ->where('symbol_id', $this->symbol_id)
            ->where('amount', $this->amount)
            ->where('price', $this->price)
            ->value('commission');
    }
}

