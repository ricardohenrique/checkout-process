<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Mail\OrderConfirmationMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderConfirmationEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(OrderPaid $event): void
    {
        $order = $event->order;

        try {
            Mail::to("user_{$order->user_id}@example.com")
                ->send(new OrderConfirmationMail($order));
        } catch (\Throwable $e) {
            Log::error("Failed to send order confirmation for order {$order->id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
