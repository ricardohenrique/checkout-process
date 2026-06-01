<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Models\LoyaltyPoint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\InteractsWithQueue;

class AwardLoyaltyPoints implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(OrderPaid $event): void
    {
        $order = $event->order;

        try {
            LoyaltyPoint::create([
                'user_id'  => $order->user_id,
                'order_id' => $order->id,
                'points'   => (int) floor($order->total),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Points were already awarded for this order — idempotent, nothing to do.
        }
    }
}
