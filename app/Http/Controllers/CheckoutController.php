<?php

namespace App\Http\Controllers;

use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    public function __construct(
        private CheckoutService $checkoutService,
    ) {}

    /**
     * POST /api/checkout
     *
     * Create an order and initiate payment.
     *
     * Request body:
     * {
     *   "items": [
     *     {"product_id": 1, "quantity": 2},
     *     {"product_id": 3, "quantity": 1}
     *   ],
     *   "discount_percent": 10
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'discount_percent' => 'nullable|integer|min:0|max:100',
        ]);

        try {
            $result = $this->checkoutService->createOrder(
                userId: $request->user()->id ?? 1, // simplified auth for this exercise
                cartItems: $validated['items'],
                discountPercent: $validated['discount_percent'] ?? 0,
            );

            return response()->json([
                'message' => 'Order created successfully',
                'data' => $result,
            ], 201);

        } catch (\App\Exceptions\InsufficientStockException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);

        } catch (\Exception $e) {
            Log::error('Checkout failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An error occurred during checkout',
            ], 500);
        }
    }

    /**
     * GET /api/orders/{id}
     */
    public function show(int $id): JsonResponse
    {
        $order = \App\Models\Order::with('items.product', 'payments')->findOrFail($id);

        return response()->json([
            'data' => $order,
        ]);
    }
}
