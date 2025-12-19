<?php

use App\Enums\OrderStatus;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Symbol;
use App\Models\Trade;
use App\Models\User;

beforeEach(function () {
    $this->symbol = Symbol::factory()->create(['name' => 'BTC']);
});

describe('GET /api/symbols', function () {
    it('returns all available symbols', function () {
        Symbol::factory()->create(['name' => 'ETH']);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/symbols');

        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonFragment(['name' => 'BTC'])
            ->assertJsonFragment(['name' => 'ETH']);
    });

    it('returns symbol id and name', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/symbols');

        $response->assertStatus(200)
            ->assertJsonStructure([
                ['id', 'name'],
            ]);
    });
});

describe('GET /api/profile', function () {
    it('returns authenticated user profile with balance and assets', function () {
        $user = User::factory()->create([
            'balance' => '10000.00',
            'locked_balance' => '500.00',
        ]);

        Asset::factory()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '1.5',
            'locked_amount' => '0.1',
        ]);

        $response = $this->actingAs($user)->getJson('/api/profile');

        $response->assertStatus(200)
            ->assertJson([
                'balance' => '10000.000000000000000000',
                'locked_balance' => '500.000000000000000000',
                'assets' => [
                    [
                        'symbol' => 'BTC',
                        'amount' => '1.500000000000000000',
                        'locked_amount' => '0.100000000000000000',
                    ],
                ],
            ]);
    });

    it('requires authentication', function () {
        $response = $this->getJson('/api/profile');

        $response->assertStatus(401);
    });
});

describe('GET /api/orders', function () {
    it('returns all orders for a symbol regardless of status', function () {
        $user = User::factory()->create();

        Order::factory()->open()->buy()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        Order::factory()->open()->sell()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '96000.00',
            'amount' => '0.02',
        ]);

        // Create a filled order that should now appear
        Order::factory()->filled()->buy()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '94000.00',
            'amount' => '0.05',
        ]);

        // Create another user's order that should not appear
        Order::factory()->open()->buy()->create([
            'symbol_id' => $this->symbol->id,
            'price' => '93000.00',
            'amount' => '0.01',
        ]);

        $response = $this->actingAs($user)->getJson('/api/orders?symbol=BTC');

        $response->assertStatus(200)
            ->assertJson([
                'symbol' => 'BTC',
            ])
            ->assertJsonCount(2, 'buy_orders')  // open + filled
            ->assertJsonCount(1, 'sell_orders');
    });

    it('includes status, commission, and executed_price fields in order response', function () {
        $user = User::factory()->create();

        Order::factory()->open()->buy()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $response = $this->actingAs($user)->getJson('/api/orders?symbol=BTC');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'symbol',
                'buy_orders' => [
                    ['id', 'side', 'price', 'amount', 'status', 'commission', 'executed_price', 'created_at'],
                ],
            ]);
    });

    it('returns commission for filled sell orders with trades', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $sellOrder = Order::factory()->filled()->sell()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $buyOrder = Order::factory()->filled()->buy()->create([
            'user_id' => $otherUser->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        // Create a trade for the filled order
        Trade::factory()->create([
            'order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buyer_id' => $otherUser->id,
            'seller_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
            'commission' => '14.25', // 950 * 0.015
        ]);

        $response = $this->actingAs($user)->getJson('/api/orders?symbol=BTC');

        $response->assertStatus(200)
            ->assertJsonPath('sell_orders.0.commission', '14.250000000000000000');
    });

    it('returns null commission for open sell orders', function () {
        $user = User::factory()->create();

        Order::factory()->open()->sell()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $response = $this->actingAs($user)->getJson('/api/orders?symbol=BTC');

        $response->assertStatus(200)
            ->assertJsonPath('sell_orders.0.commission', null);
    });

    it('returns null commission for buy orders even when filled', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $buyOrder = Order::factory()->filled()->buy()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $sellOrder = Order::factory()->filled()->sell()->create([
            'user_id' => $otherUser->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        // Create a trade for the filled order
        Trade::factory()->create([
            'order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buyer_id' => $user->id,
            'seller_id' => $otherUser->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
            'commission' => '14.25',
        ]);

        $response = $this->actingAs($user)->getJson('/api/orders?symbol=BTC');

        $response->assertStatus(200)
            ->assertJsonPath('buy_orders.0.commission', null);
    });

    it('returns commission when sell order price is lower than execution price', function () {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();

        // Sell order placed at 94000, but trade executed at buyer's price of 95000
        $sellOrder = Order::factory()->filled()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '94000.00', // Seller's asking price
            'amount' => '0.01',
        ]);

        $buyOrder = Order::factory()->filled()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00', // Buyer's price (maker price)
            'amount' => '0.01',
        ]);

        // Trade executed at buyer's (maker) price of 95000, not seller's price of 94000
        // Trade value: 0.01 * 95000 = 950 USD
        // Commission: 950 * 0.015 = 14.25 USD
        Trade::factory()->create([
            'order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00', // Execution price (buyer's price)
            'amount' => '0.01',
            'commission' => '14.25',
        ]);

        $response = $this->actingAs($seller)->getJson('/api/orders?symbol=BTC');

        $response->assertStatus(200)
            ->assertJsonPath('sell_orders.0.commission', '14.250000000000000000');
    });

    it('returns correct commission for each sell order when user has multiple orders with same amount', function () {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();

        // First sell order at 94000
        $sellOrder1 = Order::factory()->filled()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '94000.00',
            'amount' => '0.01',
        ]);

        // Second sell order at 96000 (same amount, different price)
        $sellOrder2 = Order::factory()->filled()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '96000.00',
            'amount' => '0.01',
        ]);

        $buyOrder1 = Order::factory()->filled()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $buyOrder2 = Order::factory()->filled()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '96000.00',
            'amount' => '0.01',
        ]);

        // Trade 1: executed at 95000, commission = 950 * 0.015 = 14.25
        Trade::factory()->create([
            'order_id' => $buyOrder1->id,
            'sell_order_id' => $sellOrder1->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
            'commission' => '14.25',
        ]);

        // Trade 2: executed at 96000, commission = 960 * 0.015 = 14.40
        Trade::factory()->create([
            'order_id' => $buyOrder2->id,
            'sell_order_id' => $sellOrder2->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '96000.00',
            'amount' => '0.01',
            'commission' => '14.40',
        ]);

        $response = $this->actingAs($seller)->getJson('/api/orders?symbol=BTC');

        $response->assertStatus(200);

        // Orders are sorted by price desc, so 96000 order comes first
        $sellOrders = $response->json('sell_orders');
        expect($sellOrders)->toHaveCount(2);
        expect($sellOrders[0]['commission'])->toBe('14.400000000000000000'); // 96000 order
        expect($sellOrders[1]['commission'])->toBe('14.250000000000000000'); // 94000 order
    });

    it('returns null commission for cancelled sell orders', function () {
        $user = User::factory()->create();

        Order::factory()->cancelled()->sell()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $response = $this->actingAs($user)->getJson('/api/orders?symbol=BTC');

        $response->assertStatus(200)
            ->assertJsonPath('sell_orders.0.commission', null);
    });

    it('returns commission based on execution price not order price', function () {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();

        // Sell order at 90000 (low price)
        $sellOrder = Order::factory()->filled()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '90000.00',
            'amount' => '0.01',
        ]);

        $buyOrder = Order::factory()->filled()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '100000.00', // Buyer willing to pay much more
            'amount' => '0.01',
        ]);

        // Trade executed at buyer's price (100000)
        // Commission = 1000 * 0.015 = 15.00
        Trade::factory()->create([
            'order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '100000.00', // Execution price
            'amount' => '0.01',
            'commission' => '15.00', // Based on 100000, not 90000
        ]);

        $response = $this->actingAs($seller)->getJson('/api/orders?symbol=BTC');

        $response->assertStatus(200)
            // Commission should be 15.00 (based on execution price of 100000)
            // not 13.50 (which would be based on order price of 90000)
            ->assertJsonPath('sell_orders.0.commission', '15.000000000000000000');
    });

    it('calculates higher commission when execution price exceeds sell order price', function () {
        // This test verifies that when a seller lists at a low price but the trade
        // executes at a higher buyer price, the commission is based on the higher
        // execution price (benefiting the platform, but seller still gets more overall)

        $seller = User::factory()->create([
            'balance' => '0.00',
        ]);

        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '1000.00', // Locked for buy order at 100000 * 0.01
        ]);

        Asset::factory()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.01', // Available to sell
            'locked_amount' => '0.0',
        ]);

        // Existing buy order at 100000 (maker)
        Order::factory()->open()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '100000.00',
            'amount' => '0.01',
        ]);

        // New sell order at 90000 (taker) - seller is willing to accept less
        $response = $this->actingAs($seller)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_SELL,
            'price' => '90000.00',
            'amount' => '0.01',
        ]);

        $response->assertStatus(200);

        // Trade should execute at buyer's price (100000)
        // Trade value: 100000 * 0.01 = 1000 USD
        // Commission: 1000 * 0.015 = 15 USD (NOT 900 * 0.015 = 13.50)
        // Seller receives: 1000 - 15 = 985 USD (more than 900 - 13.50 = 886.50 if executed at sell price)
        $seller->refresh();
        expect($seller->balance)->toBe('985.000000000000000000');

        // Verify the trade record has correct commission
        $this->assertDatabaseHas('trades', [
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'price' => '100000.000000000000000000',
            'commission' => '15.000000000000000000',
        ]);
    });

    it('returns executed_price for filled sell orders', function () {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();

        $sellOrder = Order::factory()->filled()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '94000.00', // Seller's asking price
            'amount' => '0.01',
        ]);

        $buyOrder = Order::factory()->filled()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        Trade::factory()->create([
            'order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00', // Execution price (buyer's price)
            'amount' => '0.01',
            'commission' => '14.25',
        ]);

        $response = $this->actingAs($seller)->getJson('/api/orders?symbol=BTC');

        $response->assertStatus(200)
            ->assertJsonPath('sell_orders.0.price', '94000.000000000000000000') // Original order price
            ->assertJsonPath('sell_orders.0.executed_price', '95000.000000000000000000'); // Actual execution price
    });

    it('returns null executed_price for open sell orders', function () {
        $user = User::factory()->create();

        Order::factory()->open()->sell()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $response = $this->actingAs($user)->getJson('/api/orders?symbol=BTC');

        $response->assertStatus(200)
            ->assertJsonPath('sell_orders.0.executed_price', null);
    });

    it('returns null executed_price for buy orders', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $buyOrder = Order::factory()->filled()->buy()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $sellOrder = Order::factory()->filled()->sell()->create([
            'user_id' => $otherUser->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        Trade::factory()->create([
            'order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buyer_id' => $user->id,
            'seller_id' => $otherUser->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
            'commission' => '14.25',
        ]);

        $response = $this->actingAs($user)->getJson('/api/orders?symbol=BTC');

        $response->assertStatus(200)
            ->assertJsonPath('buy_orders.0.executed_price', null);
    });

    it('shows executed_price higher than order price when seller gets better deal', function () {
        $seller = User::factory()->create([
            'balance' => '0.00',
        ]);

        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '1000.00',
        ]);

        Asset::factory()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.01',
            'locked_amount' => '0.0',
        ]);

        // Existing buy order at 100000 (maker)
        Order::factory()->open()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '100000.00',
            'amount' => '0.01',
        ]);

        // New sell order at 90000 (taker) - seller willing to accept less
        $this->actingAs($seller)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_SELL,
            'price' => '90000.00',
            'amount' => '0.01',
        ]);

        // Get orders for seller
        $response = $this->actingAs($seller)->getJson('/api/orders?symbol=BTC');

        $response->assertStatus(200);

        $sellOrders = $response->json('sell_orders');
        expect($sellOrders)->toHaveCount(1);
        expect($sellOrders[0]['price'])->toBe('90000.000000000000000000'); // What they asked for
        expect($sellOrders[0]['executed_price'])->toBe('100000.000000000000000000'); // What they got
        expect($sellOrders[0]['commission'])->toBe('15.000000000000000000'); // 1000 * 0.015
    });

    it('requires symbol parameter', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/orders');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['symbol']);
    });

    it('requires valid symbol', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/orders?symbol=INVALID');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['symbol']);
    });
});

describe('POST /api/orders (Buy Order)', function () {
    it('creates a buy order and locks USD', function () {
        $user = User::factory()->create([
            'balance' => '10000.00',
            'locked_balance' => '0.00',
        ]);

        $response = $this->actingAs($user)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_BUY,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Order created successfully.',
            ]);

        $user->refresh();
        expect($user->balance)->toBe('9050.000000000000000000'); // 10000 - (95000 * 0.01)
        expect($user->locked_balance)->toBe('950.000000000000000000');
    });

    it('fails if user has insufficient USD balance', function () {
        $user = User::factory()->create([
            'balance' => '100.00',
            'locked_balance' => '0.00',
        ]);

        $response = $this->actingAs($user)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_BUY,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $response->assertStatus(400)
            ->assertJson(['message' => 'Insufficient USD balance.']);
    });
});

describe('POST /api/orders (Sell Order)', function () {
    it('creates a sell order and locks asset', function () {
        $user = User::factory()->create();

        Asset::factory()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '1.0',
            'locked_amount' => '0.0',
        ]);

        $response = $this->actingAs($user)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_SELL,
            'price' => '96000.00',
            'amount' => '0.5',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Order created successfully.',
            ]);

        $asset = Asset::where('user_id', $user->id)->first();
        expect($asset->amount)->toBe('0.500000000000000000');
        expect($asset->locked_amount)->toBe('0.500000000000000000');
    });

    it('fails if user has insufficient asset balance', function () {
        $user = User::factory()->create();

        Asset::factory()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.01',
            'locked_amount' => '0.0',
        ]);

        $response = $this->actingAs($user)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_SELL,
            'price' => '96000.00',
            'amount' => '1.0',
        ]);

        $response->assertStatus(400)
            ->assertJson(['message' => 'Insufficient asset balance.']);
    });

    it('fails if user has no asset', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_SELL,
            'price' => '96000.00',
            'amount' => '0.5',
        ]);

        $response->assertStatus(400)
            ->assertJson(['message' => 'Insufficient asset balance.']);
    });
});

describe('POST /api/orders/{order}/cancel', function () {
    it('cancels a buy order and releases locked USD', function () {
        $user = User::factory()->create([
            'balance' => '9050.00',
            'locked_balance' => '950.00',
        ]);

        $order = Order::factory()->open()->buy()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $response = $this->actingAs($user)->postJson("/api/orders/{$order->id}/cancel");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Order cancelled successfully.',
            ]);

        $user->refresh();
        expect($user->balance)->toBe('10000.000000000000000000');
        expect($user->locked_balance)->toBe('0.000000000000000000');
    });

    it('cancels a sell order and releases locked asset', function () {
        $user = User::factory()->create();

        $asset = Asset::factory()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.5',
            'locked_amount' => '0.5',
        ]);

        $order = Order::factory()->open()->sell()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '96000.00',
            'amount' => '0.5',
        ]);

        $response = $this->actingAs($user)->postJson("/api/orders/{$order->id}/cancel");

        $response->assertStatus(200);

        $asset->refresh();
        expect($asset->amount)->toBe('1.000000000000000000');
        expect($asset->locked_amount)->toBe('0.000000000000000000');
    });

    it('cannot cancel another user order', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $order = Order::factory()->open()->buy()->create([
            'user_id' => $otherUser->id,
            'symbol_id' => $this->symbol->id,
        ]);

        $response = $this->actingAs($user)->postJson("/api/orders/{$order->id}/cancel");

        $response->assertStatus(404);
    });

    it('cannot cancel a filled order', function () {
        $user = User::factory()->create([
            'balance' => '1000.00',
            'locked_balance' => '0.00',
        ]);

        $order = Order::factory()->filled()->buy()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $response = $this->actingAs($user)->postJson("/api/orders/{$order->id}/cancel");

        $response->assertStatus(400)
            ->assertJson(['message' => 'Only open orders can be cancelled.']);
    });
});

describe('Order Matching', function () {
    it('matches a new buy order with existing sell order', function () {
        $buyer = User::factory()->create([
            'balance' => '10000.00',
            'locked_balance' => '0.00',
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

        // Create a new buy order at higher price that matches
        $response = $this->actingAs($buyer)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_BUY,
            'price' => '96000.00', // Willing to pay more
            'amount' => '0.01',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Order created successfully.',
            ]);

        // Check order statuses
        $sellOrder->refresh();
        expect($sellOrder->status)->toBe(OrderStatus::Filled);

        // Check balances - trade executed at seller's price (95000)
        // Trade value: 0.01 * 95000 = 950 USD
        // Commission: 950 * 0.015 = 14.25 USD (deducted from seller)
        $buyer->refresh();
        $seller->refresh();

        // Buyer: locked 960 (96000 * 0.01), refunded 10 (960 - 950)
        expect($buyer->balance)->toBe('9050.000000000000000000'); // 10000 - 960 + 10 = 9050
        expect($buyer->locked_balance)->toBe('0.000000000000000000');

        // Seller: receives 950 - 14.25 = 935.75
        expect($seller->balance)->toBe('935.750000000000000000');

        // Check asset transfers
        $sellerAsset->refresh();
        expect($sellerAsset->locked_amount)->toBe('0.000000000000000000');

        $buyerAsset = Asset::where('user_id', $buyer->id)->where('symbol_id', $this->symbol->id)->first();
        expect($buyerAsset->amount)->toBe('0.010000000000000000');
    });

    it('matches a new sell order with existing buy order', function () {
        $buyer = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '950.00', // Locked for buy order
        ]);

        $seller = User::factory()->create([
            'balance' => '0.00',
            'locked_balance' => '0.00',
        ]);

        Asset::factory()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.01',
            'locked_amount' => '0.0',
        ]);

        // Existing buy order
        $buyOrder = Order::factory()->open()->buy()->create([
            'user_id' => $buyer->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        // Create a new sell order at lower price that matches
        $response = $this->actingAs($seller)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_SELL,
            'price' => '94000.00', // Willing to sell for less
            'amount' => '0.01',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Order created successfully.',
            ]);

        // Check order statuses
        $buyOrder->refresh();
        expect($buyOrder->status)->toBe(OrderStatus::Filled);

        // Trade executed at buyer's price (95000) since buyer was existing order
        // Trade value: 0.01 * 95000 = 950 USD
        // Commission: 950 * 0.015 = 14.25 USD
        $buyer->refresh();
        $seller->refresh();

        // Buyer's locked balance released
        expect($buyer->locked_balance)->toBe('0.000000000000000000');

        // Seller receives 950 - 14.25 = 935.75
        expect($seller->balance)->toBe('935.750000000000000000');

        // Buyer gets the asset
        $buyerAsset = Asset::where('user_id', $buyer->id)->where('symbol_id', $this->symbol->id)->first();
        expect($buyerAsset->amount)->toBe('0.010000000000000000');
    });

    it('does not match orders with different amounts (no partial fills)', function () {
        $buyer = User::factory()->create([
            'balance' => '10000.00',
            'locked_balance' => '0.00',
        ]);

        $seller = User::factory()->create();

        Asset::factory()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.0',
            'locked_amount' => '0.02', // Selling 0.02
        ]);

        // Existing sell order for 0.02 BTC
        Order::factory()->open()->sell()->create([
            'user_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.02',
        ]);

        // Create buy order for 0.01 BTC - should NOT match
        $response = $this->actingAs($buyer)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_BUY,
            'price' => '96000.00',
            'amount' => '0.01',
        ]);

        $response->assertStatus(200);

        // Verify order is still open (not matched)
        $this->assertDatabaseHas('orders', [
            'user_id' => $buyer->id,
            'status' => OrderStatus::Open->value,
            'amount' => '0.010000000000000000',
        ]);
    });

    it('does not match when buy price is below sell price', function () {
        $buyer = User::factory()->create([
            'balance' => '10000.00',
            'locked_balance' => '0.00',
        ]);

        $seller = User::factory()->create();

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

        // Create buy order at 95000 - should NOT match
        $response = $this->actingAs($buyer)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_BUY,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $response->assertStatus(200);

        // Verify order is still open (not matched)
        $this->assertDatabaseHas('orders', [
            'user_id' => $buyer->id,
            'status' => OrderStatus::Open->value,
        ]);
    });

    it('cannot match with own order', function () {
        $user = User::factory()->create([
            'balance' => '10000.00',
            'locked_balance' => '0.00',
        ]);

        Asset::factory()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'amount' => '0.0',
            'locked_amount' => '0.01',
        ]);

        // User has a sell order
        Order::factory()->open()->sell()->create([
            'user_id' => $user->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        // Same user creates a matching buy order - should NOT match with self
        $response = $this->actingAs($user)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_BUY,
            'price' => '96000.00',
            'amount' => '0.01',
        ]);

        $response->assertStatus(200);

        // Verify both orders are still open (not matched with self)
        expect(Order::where('user_id', $user->id)->where('status', OrderStatus::Open)->count())->toBe(2);
    });

    it('creates a trade record on match', function () {
        $buyer = User::factory()->create([
            'balance' => '10000.00',
            'locked_balance' => '0.00',
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

        $this->actingAs($buyer)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_BUY,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $this->assertDatabaseHas('trades', [
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'symbol_id' => $this->symbol->id,
            'price' => '95000.000000000000000000',
            'amount' => '0.010000000000000000',
            'commission' => '14.250000000000000000', // 950 * 0.015 = 14.25
        ]);
    });
});

describe('Validation', function () {
    it('requires all fields for order creation', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/orders', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['symbol_id', 'side', 'price', 'amount']);
    });

    it('requires positive price and amount', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => Order::SIDE_BUY,
            'price' => '0',
            'amount' => '-1',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['price', 'amount']);
    });

    it('requires valid side value', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/orders', [
            'symbol_id' => $this->symbol->id,
            'side' => 99,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['side']);
    });

    it('requires valid symbol_id', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/orders', [
            'symbol_id' => 99999,
            'side' => Order::SIDE_BUY,
            'price' => '95000.00',
            'amount' => '0.01',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['symbol_id']);
    });
});
