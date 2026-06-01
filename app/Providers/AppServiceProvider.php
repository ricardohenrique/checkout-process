<?php

namespace App\Providers;

use App\Contracts\WarehouseClientInterface;
use App\Events\OrderPaid;
use App\Jobs\RecordPaymentAudit;
use App\Listeners\AwardLoyaltyPoints;
use App\Listeners\NotifyWarehouse;
use App\Listeners\SendOrderConfirmationEmail;
use App\Services\WarehouseClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WarehouseClientInterface::class, WarehouseClient::class);
    }

    public function boot(): void
    {
        Event::listen(OrderPaid::class, SendOrderConfirmationEmail::class);
        Event::listen(OrderPaid::class, AwardLoyaltyPoints::class);
        Event::listen(OrderPaid::class, NotifyWarehouse::class);
        Event::listen(OrderPaid::class, function (OrderPaid $event) {
            RecordPaymentAudit::dispatch(
                event: 'payment.success',
                orderId: $event->order->id,
                paymentId: $event->order->payments()->latest()->value('id'),
            );
        });
    }
}
