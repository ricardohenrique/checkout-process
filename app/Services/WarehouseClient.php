<?php

namespace App\Services;

use App\Contracts\WarehouseClientInterface;
use App\Models\Order;
use Illuminate\Support\Facades\Http;

class WarehouseClient implements WarehouseClientInterface
{
    public function notify(Order $order): void
    {
        $order->loadMissing('items.product');

        $response = Http::timeout(10)->post(
            config('services.warehouse.url', 'https://warehouse.example.com') . '/api/fulfillment',
            [
                'order_id' => $order->id,
                'items' => $order->items->map(fn ($item) => [
                    'sku' => $item->product->sku,
                    'quantity' => $item->quantity,
                ])->toArray(),
            ]
        );

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Warehouse notification failed (HTTP {$response->status()})"
            );
        }
    }
}
