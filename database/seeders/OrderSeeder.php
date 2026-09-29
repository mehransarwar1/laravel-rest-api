<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::query()->get();
        $products = Product::query()->with('variants')->get();
        $statuses = [
            OrderStatus::Pending,
            OrderStatus::Confirmed,
            OrderStatus::Processing,
            OrderStatus::Completed,
            OrderStatus::Cancelled,
            OrderStatus::Completed,
        ];

        foreach ($statuses as $index => $status) {
            $user = $users[$index % $users->count()];
            $selectedProducts = $products->slice($index * 2, 2);

            if ($selectedProducts->count() < 2) {
                $selectedProducts = $products->take(2);
            }

            DB::transaction(function () use ($user, $selectedProducts, $status): void {
                $lines = [];
                $subtotal = 0.0;

                foreach ($selectedProducts as $product) {
                    $variant = $product->variants->first();
                    $quantity = 1;
                    $unitPrice = (float) ($variant?->price ?? $product->price);
                    $lineSubtotal = round($unitPrice * $quantity, 2);
                    $subtotal = round($subtotal + $lineSubtotal, 2);

                    $lines[] = [
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'product_name' => $product->name,
                        'unit_price' => number_format($unitPrice, 2, '.', ''),
                        'quantity' => $quantity,
                        'subtotal' => number_format($lineSubtotal, 2, '.', ''),
                    ];

                    if ($variant !== null) {
                        $variant->decrement('stock', $quantity);
                    } else {
                        $product->decrement('stock', $quantity);
                    }
                }

                $tax = round($subtotal * 0.1, 2);
                $total = round($subtotal + $tax, 2);

                $order = Order::query()->create([
                    'user_id' => $user->id,
                    'order_number' => 'ORD-'.strtoupper(Str::random(8)),
                    'status' => $status,
                    'subtotal' => number_format($subtotal, 2, '.', ''),
                    'tax' => number_format($tax, 2, '.', ''),
                    'total' => number_format($total, 2, '.', ''),
                ]);

                foreach ($lines as $line) {
                    OrderItem::query()->create([
                        'order_id' => $order->id,
                        ...$line,
                    ]);
                }
            });
        }
    }
}
