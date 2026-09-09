<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Key;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Products
        $products = [
            ['sku' => 'STEAM-TOPUP-500', 'name' => 'Steam Top-up 500 ₽', 'type' => 'topup', 'price' => 500, 'stock' => 10],
            ['sku' => 'STEAM-TOPUP-1000', 'name' => 'Steam Top-up 1000 ₽', 'type' => 'topup', 'price' => 1000, 'stock' => 10],
            ['sku' => 'KEY-CS2-PRIME', 'name' => 'CS2 Prime Key', 'type' => 'key', 'price' => 1290, 'stock' => 10],
            ['sku' => 'KEY-GTA5', 'name' => 'GTA V Key', 'type' => 'key', 'price' => 1990, 'stock' => 10],
        ];
        
        foreach ($products as $p) {
            Product::create($p);
        }
        
        // Keys for each product
        $skus = ['STEAM-TOPUP-500', 'STEAM-TOPUP-1000', 'KEY-CS2-PRIME', 'KEY-GTA5'];
        
        foreach ($skus as $sku) {
            for ($i = 0; $i < 10; $i++) {
                Key::create([
                    'code' => 'KEY-' . Str::random(12),
                    'sku' => $sku,
                    'status' => 'available',
                ]);
            }
        }
    }
}
