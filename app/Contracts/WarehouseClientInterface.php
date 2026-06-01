<?php

namespace App\Contracts;

use App\Models\Order;

interface WarehouseClientInterface
{
    public function notify(Order $order): void;
}
