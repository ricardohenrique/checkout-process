<?php

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordPaymentAudit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $event,
        private ?int $orderId = null,
        private ?int $paymentId = null,
        private array $metadata = [],
    ) {}

    public function handle(): void
    {
        AuditLog::create([
            'event'      => $this->event,
            'order_id'   => $this->orderId,
            'payment_id' => $this->paymentId,
            'metadata'   => $this->metadata ?: null,
        ]);
    }
}
