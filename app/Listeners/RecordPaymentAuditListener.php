<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\RecordPaymentAudit;

class RecordPaymentAuditListener
{
    public function handle(OrderPaid $event): void
    {
        RecordPaymentAudit::dispatch(
            event: 'payment.success',
            orderId: $event->order->id,
            paymentId: $event->order->payments()->latest()->value('id'),
        );
    }
}
