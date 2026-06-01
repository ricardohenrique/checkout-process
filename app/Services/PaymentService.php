<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;

class PaymentService
{
    private string $gatewayUrl;

    public function __construct()
    {
        $this->gatewayUrl = config('services.payment_gateway.url', 'https://mock-gateway.example.com');
    }

    /**
     * Initiate a payment with the external provider.
     * Calls the payment gateway API to register the payment session and obtain a redirect URL.
     */
    public function initiate(Order $order): array
    {
        $providerReference = 'PAY-' . strtoupper(bin2hex(random_bytes(8)));

        // Call the external payment gateway to create a payment session
        $response = Http::timeout(5)->post("{$this->gatewayUrl}/api/sessions", [
            'reference'  => $providerReference,
            'amount'     => $order->total,
            'currency'   => 'EUR',
            'order_id'   => $order->id,
            'return_url' => config('app.url') . '/checkout/return',
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Payment gateway rejected session creation (HTTP {$response->status()})"
            );
        }

        $payment = Payment::create([
            'order_id'           => $order->id,
            'amount'             => $order->total,
            'status'             => Payment::STATUS_INITIATED,
            'provider_reference' => $providerReference,
            'provider'           => 'mock_gateway',
        ]);

        return [
            'payment_id'        => $payment->id,
            'provider_reference' => $providerReference,
            'redirect_url'      => "{$this->gatewayUrl}/pay/{$providerReference}",
        ];
    }

    /**
     * Process a payment callback from the provider.
     * Called when the payment provider sends a webhook notification.
     */
    public function processCallback(string $providerReference, string $status, array $metadata = []): Payment
    {
        $payment = Payment::where('provider_reference', $providerReference)->firstOrFail();

        $payment->status = $status === 'success'
            ? Payment::STATUS_SUCCESS
            : Payment::STATUS_FAILED;
        $payment->save();

        return $payment;
    }
}
