<?php

namespace App\Http\Controllers;

use App\Services\CheckoutService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentCallbackController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private CheckoutService $checkoutService,
    ) {}

    /**
     * POST /api/payments/callback
     *
     * Webhook endpoint called by the payment provider.
     *
     * Request body:
     * {
     *   "provider_reference": "PAY-XXXX",
     *   "status": "success" | "failed",
     *   "metadata": {}
     * }
     */
    public function handle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_reference' => 'required|string',
            'status' => 'required|in:success,failed',
            'metadata' => 'nullable|array',
        ]);

        try {
            $payment = $this->paymentService->processCallback(
                providerReference: $validated['provider_reference'],
                status: $validated['status'],
            );

            $order = $payment->order;

            if ($payment->status === \App\Models\Payment::STATUS_SUCCESS) {
                $this->checkoutService->handlePaymentSuccess($order);
            } else {
                $this->checkoutService->handlePaymentFailure($order);
            }

            return response()->json(['status' => 'ok']);

        } catch (\Exception $e) {
            Log::error('Payment callback processing failed', [
                'provider_reference' => $validated['provider_reference'],
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'ok']);
        }
    }
}
