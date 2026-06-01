<?php

namespace Tests\Feature;

use App\Contracts\WarehouseClientInterface;
use App\Events\OrderPaid;
use App\Jobs\RecordPaymentAudit;
use App\Listeners\AwardLoyaltyPoints;
use App\Listeners\NotifyWarehouse;
use App\Listeners\SendOrderConfirmationEmail;
use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PostPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ProductSeeder::class);

        Http::fake([
            'mock-gateway.example.com/*' => Http::response(['session' => 'ok'], 200),
            'warehouse.example.com/*'    => Http::response([], 200),
        ]);
    }

    private function placeOrderAndGetPayment(int $productId = 1, int $quantity = 1): Payment
    {
        $this->postJson('/api/checkout', [
            'items' => [['product_id' => $productId, 'quantity' => $quantity]],
        ]);

        return Payment::latest()->first();
    }

    // ── Event dispatch ────────────────────────────────────────────────────────

    public function test_success_callback_dispatches_order_paid_event(): void
    {
        Event::fake([OrderPaid::class]);

        $payment = $this->placeOrderAndGetPayment();

        $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status'             => 'success',
        ]);

        Event::assertDispatched(OrderPaid::class, fn ($e) => $e->order->id === $payment->order_id);
    }

    public function test_duplicate_success_callback_does_not_dispatch_event_twice(): void
    {
        Event::fake([OrderPaid::class]);

        $payment = $this->placeOrderAndGetPayment();

        $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status'             => 'success',
        ]);
        $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status'             => 'success',
        ]);

        Event::assertDispatchedTimes(OrderPaid::class, 1);
    }

    // ── Email ─────────────────────────────────────────────────────────────────

    public function test_confirmation_email_is_sent_on_payment_success(): void
    {
        Mail::fake();

        $payment = $this->placeOrderAndGetPayment();

        $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status'             => 'success',
        ]);

        Mail::assertSent(OrderConfirmationMail::class, fn ($mail) =>
            $mail->hasTo("user_{$payment->order->user_id}@example.com")
        );
    }

    public function test_confirmation_email_is_not_sent_on_payment_failure(): void
    {
        Mail::fake();

        $payment = $this->placeOrderAndGetPayment();

        $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status'             => 'failed',
        ]);

        Mail::assertNotSent(OrderConfirmationMail::class);
    }

    // ── Loyalty points ────────────────────────────────────────────────────────

    public function test_loyalty_points_are_awarded_on_payment_success(): void
    {
        $payment = $this->placeOrderAndGetPayment(productId: 1, quantity: 2); // 29.99 × 2 = 59.98 → 59 pts

        $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status'             => 'success',
        ]);

        $order = Order::find($payment->order_id);

        $this->assertDatabaseHas('loyalty_points', [
            'order_id' => $order->id,
            'user_id'  => $order->user_id,
            'points'   => (int) floor($order->total),
        ]);
    }

    public function test_loyalty_points_are_not_awarded_twice_on_duplicate_callback(): void
    {
        $payment = $this->placeOrderAndGetPayment();

        $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status'             => 'success',
        ]);
        $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status'             => 'success',
        ]);

        $this->assertDatabaseCount('loyalty_points', 1);
    }

    public function test_loyalty_points_are_not_awarded_on_payment_failure(): void
    {
        $payment = $this->placeOrderAndGetPayment();

        $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status'             => 'failed',
        ]);

        $this->assertDatabaseCount('loyalty_points', 0);
    }

    // ── Warehouse ─────────────────────────────────────────────────────────────

    public function test_warehouse_is_notified_with_correct_payload_on_payment_success(): void
    {
        $product = Product::find(1); // Classic White T-Shirt, sku TSH-WHT-001
        $payment = $this->placeOrderAndGetPayment(productId: 1, quantity: 2);

        $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status'             => 'success',
        ]);

        $warehouseRequest = collect(Http::recorded())
            ->first(fn ($pair) => str_contains($pair[0]->url(), 'warehouse.example.com'));

        $this->assertNotNull($warehouseRequest);
        $body = $warehouseRequest[0]->data();
        $this->assertEquals($payment->order_id, $body['order_id']);
        $this->assertEquals([['sku' => $product->sku, 'quantity' => 2]], $body['items']);
    }

    public function test_warehouse_failure_writes_audit_log_entry(): void
    {
        $this->mock(WarehouseClientInterface::class, fn ($mock) =>
            $mock->shouldReceive('notify')->andThrow(new \RuntimeException('503'))
        );

        $payment = $this->placeOrderAndGetPayment();

        $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status'             => 'success',
        ]);

        // Trigger the failed() callback directly — simulates all retries exhausted
        $listener  = new NotifyWarehouse();
        $event     = new OrderPaid(Order::find($payment->order_id));
        $exception = new \RuntimeException('503');
        $listener->failed($event, $exception);

        $this->assertDatabaseHas('audit_logs', [
            'event'    => 'warehouse.failed',
            'order_id' => $payment->order_id,
        ]);
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    public function test_audit_log_is_written_on_payment_success(): void
    {
        $payment = $this->placeOrderAndGetPayment();

        $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status'             => 'success',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event'    => 'payment.success',
            'order_id' => $payment->order_id,
        ]);
    }

    public function test_audit_log_is_not_written_on_payment_failure(): void
    {
        $payment = $this->placeOrderAndGetPayment();

        $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status'             => 'failed',
        ]);

        $this->assertDatabaseMissing('audit_logs', ['event' => 'payment.success']);
    }

    // ── Webhook response time ─────────────────────────────────────────────────

    public function test_webhook_returns_200_even_when_post_payment_listener_fails(): void
    {
        $this->mock(WarehouseClientInterface::class, fn ($mock) =>
            $mock->shouldReceive('notify')->andThrow(new \RuntimeException('unavailable'))
        );

        $payment = $this->placeOrderAndGetPayment();

        $response = $this->postJson('/api/payments/callback', [
            'provider_reference' => $payment->provider_reference,
            'status'             => 'success',
        ]);

        // Order is paid regardless of warehouse failure
        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', ['id' => $payment->order_id, 'status' => Order::STATUS_PAID]);
    }
}
