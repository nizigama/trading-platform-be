<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Events\OrderMatched;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class MatchOrderJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Order $order
    ) {}

    /**
     * Execute the job.
     */
    public function handle(OrderService $orderService): void
    {
        DB::transaction(function () use ($orderService) {
            // Re-fetch with lock to ensure order is still open
            $order = Order::lockForUpdate()->find($this->order->id);

            if (!$order || $order->status !== OrderStatus::Open) {
                return;
            }

            if ($order->side === Order::SIDE_BUY) {
                $matchingSellOrder = Order::lockForUpdate()
                    ->where('symbol_id', $order->symbol_id)
                    ->where('side', Order::SIDE_SELL)
                    ->where('status', OrderStatus::Open)
                    ->where('price', '<=', $order->price)
                    ->where('amount', $order->amount)
                    ->where('user_id', '!=', $order->user_id)
                    ->orderBy('price', 'asc')
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($matchingSellOrder) {
                    $trade = $orderService->executeTrade($order, $matchingSellOrder, $matchingSellOrder->price);

                    // Broadcast to both buyer and seller
                    broadcast(new OrderMatched($order->fresh(), $trade));
                    broadcast(new OrderMatched($matchingSellOrder->fresh(), $trade));
                }
            } else {
                $matchingBuyOrder = Order::lockForUpdate()
                    ->where('symbol_id', $order->symbol_id)
                    ->where('side', Order::SIDE_BUY)
                    ->where('status', OrderStatus::Open)
                    ->where('price', '>=', $order->price)
                    ->where('amount', $order->amount)
                    ->where('user_id', '!=', $order->user_id)
                    ->orderBy('price', 'desc')
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($matchingBuyOrder) {
                    $trade = $orderService->executeTrade($matchingBuyOrder, $order, $matchingBuyOrder->price);

                    // Broadcast to both buyer and seller
                    broadcast(new OrderMatched($matchingBuyOrder->fresh(), $trade));
                    broadcast(new OrderMatched($order->fresh(), $trade));
                }
            }
        });
    }
}
