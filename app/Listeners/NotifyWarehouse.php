<?php

namespace App\Listeners;

use App\Contracts\WarehouseClientInterface;
use App\Events\OrderPaid;
use App\Jobs\RecordPaymentAudit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class NotifyWarehouse implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 5;
    public array $backoff = [10, 30, 60, 120, 300];
    public int $timeout = 10;

    public function __construct(private WarehouseClientInterface $client) {}

    public function handle(OrderPaid $event): void
    {
        $this->client->notify($event->order);
    }

    public function failed(OrderPaid $event, \Throwable $exception): void
    {
        RecordPaymentAudit::dispatch(
            event: 'warehouse.failed',
            orderId: $event->order->id,
            metadata: ['error' => $exception->getMessage()],
        );
    }
}
