<?php

namespace App\Services;

use App\Events\OrderPaid;
use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckoutService
{
    public function __construct(
        private StockService $stockService,
        private PaymentService $paymentService,
    ) {}

    /**
     * Create an order from the given cart items and initiate payment.
     *
     * @param int $userId
     * @param array $cartItems Array of ['product_id' => int, 'quantity' => int]
     * @param int $discountPercent
     * @return array Order details and payment redirect URL
     */
    public function createOrder(int $userId, array $cartItems, int $discountPercent = 0): array
    {
        $order = DB::transaction(function () use ($userId, $cartItems, $discountPercent) {

            // Reserve stock
            $this->stockService->reserve($cartItems);

            // Create the order
            $order = Order::create([
                'user_id' => $userId,
                'status' => Order::STATUS_PENDING,
                'total' => 0,
                'discount_percent' => $discountPercent,
            ]);

            // Create order items
            $products = Product::whereIn('id', array_column($cartItems, 'product_id'))->get()->keyBy('id');
            foreach ($cartItems as $item) {
                $product = $products[$item['product_id']];

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'price' => $product->price,
                ]);
            }

            // Calculate and save total
            $order->load('items');
            $order->total = $order->calculateTotal();
            $order->save();

            return $order;
        });

        // Initiate payment outside the transaction so the DB connection is not
        // held open during the external HTTP call.
        try {
            $paymentResult = $this->paymentService->initiate($order);
        } catch (PaymentGatewayException $e) {
            $this->handlePaymentFailure($order);
            throw $e;
        }

        return [
            'order_id' => $order->id,
            'total' => $order->total,
            'status' => $order->status,
            'payment_url' => $paymentResult['redirect_url'],
        ];
    }

    /**
     * Handle a successful payment: update order status and trigger post-payment actions.
     */
    public function handlePaymentSuccess(Order $order): void
    {
        if ($order->status === Order::STATUS_PAID) {
            return;
        }

        $order->status = Order::STATUS_PAID;
        $order->save();

        OrderPaid::dispatch($order);

        Log::info("Order {$order->id} marked as paid.");
    }

    /**
     * Handle a failed payment: update order and release stock.
     */
    public function handlePaymentFailure(Order $order): void
    {
        $order->status = Order::STATUS_FAILED;
        $order->save();

        // Release reserved stock
        $items = $order->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
        ])->toArray();

        $this->stockService->release($items);

        Log::info("Order {$order->id} failed. Stock released.");
    }
}
