<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['name' => 'Classic White T-Shirt', 'sku' => 'TSH-WHT-001', 'price' => 29.99, 'stock_quantity' => 150],
            ['name' => 'Slim Fit Jeans', 'sku' => 'JNS-SLM-001', 'price' => 89.95, 'stock_quantity' => 75],
            ['name' => 'Leather Sneakers', 'sku' => 'SNK-LTH-001', 'price' => 149.90, 'stock_quantity' => 30],
            ['name' => 'Wool Blend Coat', 'sku' => 'COT-WOL-001', 'price' => 249.99, 'stock_quantity' => 20],
            ['name' => 'Cotton Socks (3-pack)', 'sku' => 'SCK-CTN-003', 'price' => 14.97, 'stock_quantity' => 500],
            ['name' => 'Silk Scarf', 'sku' => 'SCF-SLK-001', 'price' => 59.99, 'stock_quantity' => 3],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}
