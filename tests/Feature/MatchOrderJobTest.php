<?php

use App\Enums\OrderStatus;
use App\Events\OrderMatched;
use App\Jobs\MatchOrderJob;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Symbol;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->symbol = Symbol::factory()->create(['name' => 'BTC']);
});

describe('MatchOrderJob', function () {
    it('dispatches job when order is created', function () {
        Queue::fake();

        $user = User::factory()->create([
            'balance' => '10000.00',
            'locked_balance' => '0.00',
        ]);

        $this->actingAs($user)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_BUY,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        Queue::assertPushed(MatchOrderJob::class, function ($job) {
            return $job->order->price === '95000.000000000000000000'
                && $job->order->amount === '0.010000000000000000';
        });
    });

    it('matches buy order with existing sell order', function () {
        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '960.00', // Already locked for buy order
        ]);

        $seller = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '0.00',
        ]);

        $sellerAsset = Asset::factory()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.0',
            'locked_amount' => '0.01',
        ]);

        // Existing sell order
        $sellOrder = Order::factory()->open()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        // Buy order that should match
        $buyOrder = Order::factory()->open()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '96000.00',
            'amount' => '0.01',
        ]);

        // Dispatch and handle the job
        $job = new MatchOrderJob($buyOrder);
        $job->handle(app(\App\Services\OrderService::class));

        // Check order statuses
        $buyOrder->refresh();
        $sellOrder->refresh();
        expect($buyOrder->status)->toBe(OrderStatus::Filled);
        expect($sellOrder->status)->toBe(OrderStatus::Filled);

        // Check balances
        $buyer->refresh();
        $seller->refresh();

        // Buyer: locked 960, trade at buyer's price 960, no refund
        expect($buyer->balance)->toBe('0.000000000000000000');
        expect($buyer->locked_balance)->toBe('0.000000000000000000');

        // Seller: receives 960 - 14.40 (960 * 0.015) = 945.60
        expect($seller->balance)->toBe('945.600000000000000000');

        // Check asset transfers
        $sellerAsset->refresh();
        expect($sellerAsset->locked_amount)->toBe('0.000000000000000000');

        $buyerAsset = Asset::where('user_id', $buyer->id)->where('symbol_id', $this->symbol->id)->first();
        expect($buyerAsset->amount)->toBe('0.010000000000000000');
    });

    it('matches sell order with existing buy order', function () {
        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '950.00',
        ]);

        $seller = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '0.00',
        ]);

        Asset::factory()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.0',
            'locked_amount' => '0.01',
        ]);

        // Existing buy order
        $buyOrder = Order::factory()->open()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        // Sell order that should match
        $sellOrder = Order::factory()->open()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '94000.00',
            'amount' => '0.01',
        ]);

        // Dispatch and handle the job
        $job = new MatchOrderJob($sellOrder);
        $job->handle(app(\App\Services\OrderService::class));

        // Check order statuses
        $buyOrder->refresh();
        $sellOrder->refresh();
        expect($buyOrder->status)->toBe(OrderStatus::Filled);
        expect($sellOrder->status)->toBe(OrderStatus::Filled);

        // Check balances
        $buyer->refresh();
        $seller->refresh();

        expect($buyer->locked_balance)->toBe('0.000000000000000000');
        expect($seller->balance)->toBe('935.750000000000000000');
    });

    it('does not match if order is already filled', function () {
        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '950.00',
        ]);

        $seller = User::factory()->create([
            'balance' => '0.00',
        ]);

        Asset::factory()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.0',
            'locked_amount' => '0.01',
        ]);

        // Existing sell order
        Order::factory()->open()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        // Buy order that is already filled
        $buyOrder = Order::factory()->filled()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '96000.00',
            'amount' => '0.01',
        ]);

        // Dispatch and handle the job
        $job = new MatchOrderJob($buyOrder);
        $job->handle(app(\App\Services\OrderService::class));

        // Seller's balance should remain unchanged (no trade executed)
        $seller->refresh();
        expect($seller->balance)->toBe('0.000000000000000000');
    });

    it('does not match if order is cancelled', function () {
        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '950.00',
        ]);

        $seller = User::factory()->create([
            'balance' => '0.00',
        ]);

        Asset::factory()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.0',
            'locked_amount' => '0.01',
        ]);

        // Existing sell order
        Order::factory()->open()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        // Buy order that is cancelled
        $buyOrder = Order::factory()->cancelled()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '96000.00',
            'amount' => '0.01',
        ]);

        // Dispatch and handle the job
        $job = new MatchOrderJob($buyOrder);
        $job->handle(app(\App\Services\OrderService::class));

        // Seller's balance should remain unchanged (no trade executed)
        $seller->refresh();
        expect($seller->balance)->toBe('0.000000000000000000');
    });

    it('does not match orders with different amounts', function () {
        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '960.00',
        ]);

        $seller = User::factory()->create([
            'balance' => '0.00',
        ]);

        Asset::factory()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.0',
            'locked_amount' => '0.02',
        ]);

        // Existing sell order for 0.02
        Order::factory()->open()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.02',
        ]);

        // Buy order for 0.01 - should NOT match
        $buyOrder = Order::factory()->open()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '96000.00',
            'amount' => '0.01',
        ]);

        // Dispatch and handle the job
        $job = new MatchOrderJob($buyOrder);
        $job->handle(app(\App\Services\OrderService::class));

        // Buy order should still be open
        $buyOrder->refresh();
        expect($buyOrder->status)->toBe(OrderStatus::Open);
    });

    it('does not match when buy price is below sell price', function () {
        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '950.00',
        ]);

        $seller = User::factory()->create([
            'balance' => '0.00',
        ]);

        Asset::factory()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.0',
            'locked_amount' => '0.01',
        ]);

        // Existing sell order at 96000
        Order::factory()->open()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '96000.00',
            'amount' => '0.01',
        ]);

        // Buy order at 95000 - should NOT match
        $buyOrder = Order::factory()->open()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        // Dispatch and handle the job
        $job = new MatchOrderJob($buyOrder);
        $job->handle(app(\App\Services\OrderService::class));

        // Buy order should still be open
        $buyOrder->refresh();
        expect($buyOrder->status)->toBe(OrderStatus::Open);
    });

    it('does not match with own order', function () {
        $user = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '960.00',
        ]);

        Asset::factory()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.0',
            'locked_amount' => '0.01',
        ]);

        // User's own sell order
        Order::factory()->open()->sell()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        // User's buy order - should NOT match with self
        $buyOrder = Order::factory()->open()->buy()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '96000.00',
            'amount' => '0.01',
        ]);

        // Dispatch and handle the job
        $job = new MatchOrderJob($buyOrder);
        $job->handle(app(\App\Services\OrderService::class));

        // Both orders should still be open
        $buyOrder->refresh();
        expect($buyOrder->status)->toBe(OrderStatus::Open);
        expect(Order::where('user_id', $user->id)->where('status', OrderStatus::Open)->count())->toBe(2);
    });

    it('creates trade record on successful match', function () {
        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '950.00',
        ]);

        $seller = User::factory()->create([
            'balance' => '0.00',
        ]);

        Asset::factory()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.0',
            'locked_amount' => '0.01',
        ]);

        $sellOrder = Order::factory()->open()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $buyOrder = Order::factory()->open()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        // Dispatch and handle the job
        $job = new MatchOrderJob($buyOrder);
        $job->handle(app(\App\Services\OrderService::class));

        $this->assertDatabaseHas('trades', [
            'order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.000000000000000000',
            'amount' => '0.010000000000000000',
            'commission' => '14.250000000000000000',
        ]);
    });

    it('creates trade with correct sell_order_id when sell price differs from execution price', function () {
        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '950.00', // Locked at buyer's price
        ]);

        $seller = User::factory()->create([
            'balance' => '0.00',
        ]);

        Asset::factory()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.0',
            'locked_amount' => '0.01',
        ]);

        // Existing buy order at 95000 (maker)
        $buyOrder = Order::factory()->open()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        // New sell order at lower price 94000 (taker)
        $sellOrder = Order::factory()->open()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '94000.00',
            'amount' => '0.01',
        ]);

        // Dispatch and handle the job for sell order
        $job = new MatchOrderJob($sellOrder);
        $job->handle(app(\App\Services\OrderService::class));

        // Trade should be at buyer's price (95000), with correct order IDs
        $this->assertDatabaseHas('trades', [
            'order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'price' => '95000.000000000000000000', // Executed at maker's (buyer's) price
            'amount' => '0.010000000000000000',
            'commission' => '14.250000000000000000', // 950 * 0.015
        ]);
    });

    it('broadcasts OrderMatched event to both buyer and seller on successful match', function () {
        Event::fake([OrderMatched::class]);

        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '950.00',
        ]);

        $seller = User::factory()->create([
            'balance' => '0.00',
        ]);

        Asset::factory()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.0',
            'locked_amount' => '0.01',
        ]);

        $sellOrder = Order::factory()->open()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $buyOrder = Order::factory()->open()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        // Dispatch and handle the job
        $job = new MatchOrderJob($buyOrder);
        $job->handle(app(\App\Services\OrderService::class));

        // Assert OrderMatched was broadcast twice (once for buyer, once for seller)
        Event::assertDispatched(OrderMatched::class, 2);

        // Assert broadcast to buyer
        Event::assertDispatched(OrderMatched::class, function ($event) use ($buyer) {
            return $event->order->user_id === $buyer->id;
        });

        // Assert broadcast to seller
        Event::assertDispatched(OrderMatched::class, function ($event) use ($seller) {
            return $event->order->user_id === $seller->id;
        });
    });

    it('broadcasts OrderMatched event on private user channel', function () {
        Event::fake([OrderMatched::class]);

        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '950.00',
        ]);

        $seller = User::factory()->create([
            'balance' => '0.00',
        ]);

        Asset::factory()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.0',
            'locked_amount' => '0.01',
        ]);

        Order::factory()->open()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $buyOrder = Order::factory()->open()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $job = new MatchOrderJob($buyOrder);
        $job->handle(app(\App\Services\OrderService::class));

        // Verify the event broadcasts on the correct private channel
        Event::assertDispatched(OrderMatched::class, function ($event) {
            $channels = $event->broadcastOn();

            return count($channels) === 1
                && $channels[0]->name === 'private-private-user.'.$event->order->user_id;
        });
    });

    it('does not broadcast OrderMatched when no match occurs', function () {
        Event::fake([OrderMatched::class]);

        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '950.00',
        ]);

        // Buy order with no matching sell order
        $buyOrder = Order::factory()->open()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $job = new MatchOrderJob($buyOrder);
        $job->handle(app(\App\Services\OrderService::class));

        Event::assertNotDispatched(OrderMatched::class);
    });

    it('handles deleted order gracefully', function () {
        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '950.00',
        ]);

        $buyOrder = Order::factory()->open()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        // Create job with order
        $job = new MatchOrderJob($buyOrder);

        // Delete the order before job runs
        $buyOrder->delete();

        // Job should not throw an exception
        $job->handle(app(\App\Services\OrderService::class));

        // Just verify no exception was thrown - test passes if we get here
        expect(true)->toBeTrue();
    });
});
