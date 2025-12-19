<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateOrderRequest;
use App\Models\Order;
use App\Models\Symbol;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orderService
    ) {}

    /**
     * GET /api/symbols
     * Returns all available trading symbols.
     */
    public function symbols(): JsonResponse
    {
        $symbols = Symbol::all()->map(fn ($symbol) => [
            'id' => $symbol->id,
            'name' => $symbol->name,
        ]);

        return response()->json($symbols);
    }

    /**
     * GET /api/orders?symbol=BTC
     * Returns all orders for the given symbol.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'symbol' => 'required|string|exists:symbols,name',
        ]);

        $results = $this->orderService->getOrdersForSymbol($request->user(), $request->symbol);

        return response()->json($results);
    }

    /**
     * POST /api/orders
     * Creates a limit order.
     */
    public function store(CreateOrderRequest $request): JsonResponse
    {
        $this->orderService->createOrder(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'message' => 'Order created successfully.',
        ]);
    }

    /**
     * POST /api/orders/{order}/cancel
     * Cancels an open order.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        // Ensure the order belongs to the authenticated user
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Order not found.',
            ], 404);
        }

        $this->orderService->cancelOrder($order);

        return response()->json([
            'message' => 'Order cancelled successfully.',
        ]);
    }
}
