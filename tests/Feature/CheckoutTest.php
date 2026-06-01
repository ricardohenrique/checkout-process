<?php

namespace Tests\Feature;

use App\Exceptions\PaymentGatewayException;
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

    // Bug 1 — Race condition: stock reservation must be atomic
    public function test_stock_reservation_depletes_to_zero_and_blocks_further_orders(): void
    {
        $product = Product::find(6); // Silk Scarf — 3 in stock
        $this->assertEquals(3, $product->stock_quantity);

        // Reserve all 3 units
        $this->postJson('/api/checkout', [
            'items' => [['product_id' => 6, 'quantity' => 3]],
        ])->assertStatus(201);

        $product->refresh();
        $this->assertEquals(0, $product->stock_quantity);

        // Any subsequent attempt must be rejected
        $this->postJson('/api/checkout', [
            'items' => [['product_id' => 6, 'quantity' => 1]],
        ])->assertStatus(409);
    }

    // Bug 2 — HTTP call outside transaction: gateway failure must release stock
    public function test_gateway_failure_releases_stock_and_marks_order_failed(): void
    {
        $this->mock(\App\Services\PaymentService::class, function ($mock) {
            $mock->shouldReceive('initiate')->andThrow(new PaymentGatewayException('Gateway unavailable'));
        });

        $product = Product::find(1);
        $initialStock = $product->stock_quantity;

        $this->postJson('/api/checkout', [
            'items' => [['product_id' => 1, 'quantity' => 2]],
        ])->assertStatus(500);

        $product->refresh();
        $this->assertEquals($initialStock, $product->stock_quantity);
        $this->assertDatabaseHas('orders', ['status' => Order::STATUS_FAILED]);
    }

    // Bug 3 — Rounding: amount forwarded to gateway must be rounded to cents
    public function test_payment_amount_sent_to_gateway_is_rounded(): void
    {
        // 29.99 * (1 - 0.15) = 25.4915 — must be sent as 25.49
        $this->postJson('/api/checkout', [
            'items' => [['product_id' => 1, 'quantity' => 1]],
            'discount_percent' => 15,
        ])->assertStatus(201);

        $sessionRequest = collect(Http::recorded())
            ->first(fn ($pair) => str_contains($pair[0]->url(), '/api/sessions'));

        $this->assertEquals(25.49, $sessionRequest[0]->data()['amount']);
    }

    // Bug 4 — Idempotency: duplicate success callback must not re-process the order
    public function test_duplicate_success_callback_is_idempotent(): void
    {
        $this->postJson('/api/checkout', [
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ]);

        $payment = Payment::latest()->first();
        $ref = $payment->provider_reference;

        $this->postJson('/api/payments/callback', ['provider_reference' => $ref, 'status' => 'success']);
        $this->postJson('/api/payments/callback', ['provider_reference' => $ref, 'status' => 'success']);

        $this->assertDatabaseHas('payments', ['provider_reference' => $ref, 'status' => Payment::STATUS_SUCCESS]);
        $this->assertDatabaseHas('orders', ['id' => $payment->order_id, 'status' => Order::STATUS_PAID]);
    }

    // Bug 4 — Idempotency: a late "failed" callback must not downgrade a paid order
    public function test_failed_callback_after_success_does_not_downgrade_order(): void
    {
        $this->postJson('/api/checkout', [
            'items' => [['product_id' => 6, 'quantity' => 1]], // Silk Scarf
        ]);

        $payment = Payment::latest()->first();
        $ref = $payment->provider_reference;

        // First callback: success
        $this->postJson('/api/payments/callback', ['provider_reference' => $ref, 'status' => 'success']);

        $product = Product::find(6);
        $stockAfterPaid = $product->stock_quantity; // reserved — not yet released

        // Late retry with failed status
        $this->postJson('/api/payments/callback', ['provider_reference' => $ref, 'status' => 'failed']);

        $product->refresh();
        $this->assertEquals($stockAfterPaid, $product->stock_quantity); // stock must not be released again
        $this->assertDatabaseHas('orders', ['id' => $payment->order_id, 'status' => Order::STATUS_PAID]);
    }
}
