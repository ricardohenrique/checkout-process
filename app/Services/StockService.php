<?php

namespace App\Services;

use App\Models\Product;
use App\Exceptions\InsufficientStockException;

class StockService
{
    /**
     * Reserve stock for the given items.
     *
     * @param array $items Array of ['product_id' => int, 'quantity' => int]
     * @throws InsufficientStockException
     */
    public function reserve(array $items): void
    {
        foreach ($items as $item) {
            $product = Product::lockForUpdate()->findOrFail($item['product_id']);

            if ($product->stock_quantity < $item['quantity']) {
                throw new InsufficientStockException(
                    "Insufficient stock for product {$product->name}. " .
                    "Available: {$product->stock_quantity}, requested: {$item['quantity']}"
                );
            }

            $product->stock_quantity -= $item['quantity'];
            $product->save();
        }
    }

    /**
     * Release previously reserved stock.
     */
    public function release(array $items): void
    {
        foreach ($items as $item) {
            $product = Product::find($item['product_id']);
            if ($product) {
                $product->increment('stock_quantity', $item['quantity']);
            }
        }
    }
}
