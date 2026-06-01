<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ProductSeeder::class);

        // Fake the external payment gateway so tests don't make real HTTP calls
        Http::fake([
            'mock-gateway.example.com/*' => Http::response(['session' => 'ok'], 200),
        ]);
    }

    public function test_can_create_order_with_valid_items(): void
    {
        $response = $this->postJson('/api/checkout', [
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
                ['product_id' => 5, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => ['order_id', 'total', 'status', 'payment_url'],
            ]);

        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.order_id'),
            'status' => 'pending',
        ]);
    }

    public function test_checkout_fails_with_insufficient_stock(): void
    {
        // Silk Scarf has only 3 in stock
        $response = $this->postJson('/api/checkout', [
            'items' => [
                ['product_id' => 6, 'quantity' => 10],
            ],
        ]);

        $response->assertStatus(409);
    }

    public function test_payment_callback_marks_order_as_paid(): void
    {
        // Create an order first
        $orderResponse = $this->postJson('/api/checkout', [
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ]);

        $payment = Payment::latest()->first();

        // Simulate successful payment callback
        $response = $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status' => 'success',
        ]);

        $response->assertStatus(200);

        $order = Order::find($orderResponse->json('data.order_id'));
        $this->assertEquals('paid', $order->status);
    }

    public function test_failed_payment_releases_stock(): void
    {
        $product = Product::find(6); // Silk Scarf, 3 in stock
        $initialStock = $product->stock_quantity;

        // Create order for 2 scarves
        $this->postJson('/api/checkout', [
            'items' => [
                ['product_id' => 6, 'quantity' => 2],
            ],
        ]);

        $payment = Payment::latest()->first();

        // Simulate failed payment
        $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status' => 'failed',
        ]);

        $product->refresh();
        $this->assertEquals($initialStock, $product->stock_quantity);
    }

    public function test_order_total_with_discount(): void
    {
        $response = $this->postJson('/api/checkout', [
            'items' => [
                ['product_id' => 1, 'quantity' => 1], // 29.99
            ],
            'discount_percent' => 15,
        ]);

        $response->assertStatus(201);

        $order = Order::find($response->json('data.order_id'));
        $this->assertEquals(25.49, $order->total);
    }

    public function test_can_view_order_details(): void
    {
        $orderResponse = $this->postJson('/api/checkout', [
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
                ['product_id' => 2, 'quantity' => 1],
            ],
        ]);

        $orderId = $orderResponse->json('data.order_id');

        $response = $this->getJson("/api/orders/{$orderId}");
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id', 'status', 'total', 'items', 'payments',
                ],
            ]);
    }
}
