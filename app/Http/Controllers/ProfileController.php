<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * GET /api/profile
     * Returns authenticated user's USD balance and asset balances.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $assets = $user->assets()->with('symbol')->get()->map(fn ($asset) => [
            'symbol' => $asset->symbol->name,
            'amount' => $asset->amount,
            'locked_amount' => $asset->locked_amount,
        ]);

        return response()->json([
            'balance' => $user->balance,
            'locked_balance' => $user->locked_balance,
            'assets' => $assets,
        ]);
    }
}
