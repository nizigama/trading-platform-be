<?php

namespace App\Events;

use App\Models\Order;
use App\Models\Trade;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderMatched implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Order $order,
        public Trade $trade
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('private-user.' . $this->order->user_id),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order' => [
                'id' => $this->order->id,
                'side' => $this->order->side,
                'price' => $this->order->price,
                'amount' => $this->order->amount,
                'status' => $this->order->status->value,
            ],
            'trade' => [
                'id' => $this->trade->id,
                'price' => $this->trade->price,
                'amount' => $this->trade->amount,
                'commission' => $this->trade->commission,
                'created_at' => $this->trade->created_at->toIso8601String(),
            ],
        ];
    }
}
