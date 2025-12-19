<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\OrderException;
use App\Jobs\MatchOrderJob;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Symbol;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * Get orders for a user and symbol, separated by buy/sell.
     *
     * @return array{symbol: string, buy_orders: array, sell_orders: array}
     */
    public function getOrdersForSymbol(User $user, string $symbol): array
    {
        $symbol = Symbol::where('name', $symbol)->first();

        $orders = Order::with('sellTrade')
            ->where('symbol_id', $symbol->id)
            ->where('user_id', $user->id)
            ->orderBy('price', 'desc')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($order) => [
                'id' => $order->id,
                'side' => $order->side,
                'price' => $order->price,
                'amount' => $order->amount,
                'status' => $order->status->value,
                'commission' => $order->sell_commission,
                'executed_price' => $order->sell_execution_price,
                'created_at' => $order->created_at->toIso8601String(),
            ]);

        return [
            'symbol' => $symbol->name,
            'buy_orders' => $orders->filter(fn ($order) => $order['side'] === Order::SIDE_BUY)->values()->all(),
            'sell_orders' => $orders->filter(fn ($order) => $order['side'] === Order::SIDE_SELL)->values()->all(),
        ];
    }

    /**
     * Create a new order and attempt to match it.
     */
    public function createOrder(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data) {
            $symbol = Symbol::find($data['symbol_id']);
            $side = $data['side'];
            $price = $this->toDecimal($data['price']);
            $amount = $this->toDecimal($data['amount']);

            if ($side === Order::SIDE_BUY) {
                $user = User::lockForUpdate()->find($user->id);
                $totalCost = bcmul($price, $amount, 18);

                if (bccomp($this->toDecimal($user->balance), $totalCost, 18) < 0) {
                    throw new InsufficientBalanceException('Insufficient USD balance.');
                }

                $user->update([
                    'balance' => bcsub($this->toDecimal($user->balance), $totalCost, 18),
                    'locked_balance' => bcadd($this->toDecimal($user->locked_balance), $totalCost, 18),
                ]);

            } else {

                $asset = Asset::lockForUpdate()
                    ->where('user_id', $user->id)
                    ->where('symbol_id', $symbol->id)
                    ->first();

                if (! $asset || bccomp($this->toDecimal($asset->amount), $amount, 18) < 0) {
                    throw new InsufficientBalanceException('Insufficient asset balance.');
                }

                $asset->update([
                    'amount' => bcsub($this->toDecimal($asset->amount), $amount, 18),
                    'locked_amount' => bcadd($this->toDecimal($asset->locked_amount), $amount, 18),
                ]);
            }

            $order = Order::create([
                'user_id' => $user->id,
                'symbol_id' => $symbol->id,
                'side' => $side,
                'price' => $price,
                'amount' => $amount,
                'status' => OrderStatus::Open,
            ]);

            MatchOrderJob::dispatch($order);
        });
    }

    /**
     * Cancel an open order.
     */
    public function cancelOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            // Re-fetch with lock to prevent race conditions
            $order = Order::lockForUpdate()->findOrFail($order->id);

            if ($order->status !== OrderStatus::Open) {
                throw new OrderException('Only open orders can be cancelled.');
            }

            if ($order->side === Order::SIDE_BUY) {
                $user = User::lockForUpdate()->find($order->user_id);
                $totalCost = bcmul($this->toDecimal($order->price), $this->toDecimal($order->amount), 18);

                $user->update([
                    'balance' => bcadd($this->toDecimal($user->balance), $totalCost, 18),
                    'locked_balance' => bcsub($this->toDecimal($user->locked_balance), $totalCost, 18),
                ]);

            } else {
                $asset = Asset::lockForUpdate()
                    ->where('user_id', $order->user_id)
                    ->where('symbol_id', $order->symbol_id)
                    ->first();

                $asset->update([
                    'amount' => bcadd($this->toDecimal($asset->amount), $this->toDecimal($order->amount), 18),
                    'locked_amount' => bcsub($this->toDecimal($asset->locked_amount), $this->toDecimal($order->amount), 18),
                ]);
            }

            $order->update(['status' => OrderStatus::Cancelled]);
        });
    }

    /**
     * Execute a trade between a buy order and a sell order.
     * Uses the maker's price (passed as parameter).
     */
    public function executeTrade(Order $buyOrder, Order $sellOrder, string $executionPrice): Trade
    {

        $rate = config('app.sale_commission_rate');
        
        $amount = $this->toDecimal($buyOrder->amount);
        $tradeValue = bcmul($executionPrice, $amount, 18);
        $commission = bcmul($tradeValue, $rate, 18);
        $sellerProceeds = bcsub($tradeValue, $commission, 18);

        $buyer = User::lockForUpdate()->find($buyOrder->user_id);
        $seller = User::lockForUpdate()->find($sellOrder->user_id);

        // Calculate the buyer's originally locked amount
        $buyerLockedAmount = bcmul($this->toDecimal($buyOrder->price), $this->toDecimal($buyOrder->amount), 18);

        // Release buyer's locked USD
        $buyer->update([
            'locked_balance' => bcsub($this->toDecimal($buyer->locked_balance), $buyerLockedAmount, 18),
        ]);

        // If buyer locked more than trade value (their price > execution price), refund difference
        if (bccomp($buyerLockedAmount, $tradeValue, 18) > 0) {
            $refund = bcsub($buyerLockedAmount, $tradeValue, 18);
            $buyer->refresh();
            $buyer->update([
                'balance' => bcadd($this->toDecimal($buyer->balance), $refund, 18),
            ]);
        }

        // Seller receives USD minus commission
        $seller->update([
            'balance' => bcadd($this->toDecimal($seller->balance), $sellerProceeds, 18),
        ]);

        // Release seller's locked asset
        $sellerAsset = Asset::lockForUpdate()
            ->where('user_id', $seller->id)
            ->where('symbol_id', $sellOrder->symbol_id)
            ->first();

        $sellerAsset->update([
            'locked_amount' => bcsub($this->toDecimal($sellerAsset->locked_amount), $amount, 18),
        ]);

        // Transfer asset to buyer
        $buyerAsset = Asset::lockForUpdate()
            ->where('user_id', $buyer->id)
            ->where('symbol_id', $buyOrder->symbol_id)
            ->first();

        if ($buyerAsset) {
            $buyerAsset->update([
                'amount' => bcadd($this->toDecimal($buyerAsset->amount), $amount, 18),
            ]);
        } else {
            Asset::create([
                'user_id' => $buyer->id,
                'symbol_id' => $buyOrder->symbol_id,
                'amount' => $amount,
                'locked_amount' => '0',
            ]);
        }

        // Create trade record
        $trade = Trade::create([
            'order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'symbol_id' => $buyOrder->symbol_id,
            'price' => $executionPrice,
            'amount' => $amount,
            'commission' => $commission,
        ]);

        // Mark both orders as filled
        $buyOrder->update(['status' => OrderStatus::Filled]);
        $sellOrder->update(['status' => OrderStatus::Filled]);

        return $trade;
    }

    /**
     * Convert a value to a decimal string suitable for BC math functions.
     */
    private function toDecimal(mixed $value): string
    {
        if ($value === null) {
            return '0';
        }

        // Convert to string first
        $str = (string) $value;

        // Handle scientific notation (e.g., 1.5E-8)
        if (stripos($str, 'e') !== false) {
            $str = sprintf('%.18f', (float) $value);
        }

        return $str;
    }
}
