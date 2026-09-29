<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\OrderProcessingException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderService
{
    /**
     * @param  list<array{product_id: int, product_variant_id?: int|null, quantity: int}>  $items
     */
    public function create(User $user, array $items): Order
    {
        $lines = $this->normalizeLines($items);

        return DB::transaction(function () use ($user, $lines): Order {
            $inventory = $this->lockInventory($lines);
            $prepared = $this->prepareLines($lines, $inventory);

            $subtotalCents = 0;
            foreach ($prepared as $line) {
                $subtotalCents += $line['subtotal_cents'];
            }

            if ($subtotalCents > Money::MAX_CENTS) {
                throw new OrderProcessingException('Order total exceeds the allowed maximum.', 422);
            }

            $subtotal = Money::fromCents($subtotalCents);
            $order = $this->createOrderRecord($user->id, $subtotal);

            foreach ($prepared as $line) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $line['product_id'],
                    'product_variant_id' => $line['product_variant_id'],
                    'product_name' => $line['product_name'],
                    'unit_price' => Money::fromCents($line['unit_cents']),
                    'quantity' => $line['quantity'],
                    'subtotal' => Money::fromCents($line['subtotal_cents']),
                ]);

                $this->decrementStock($line, $inventory);
            }

            return $order->load('orderItems');
        });
    }

    public function cancel(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::Cancelled) {
                throw new OrderProcessingException('Order is already cancelled.', 409);
            }

            if (! $locked->status->canBeCancelled()) {
                throw new OrderProcessingException('This order cannot be cancelled.', 409);
            }

            $locked->load('orderItems');
            $this->lockInventoryForItems($locked->orderItems);

            foreach ($locked->orderItems as $item) {
                if ($item->product_variant_id !== null) {
                    ProductVariant::query()
                        ->whereKey($item->product_variant_id)
                        ->increment('stock', $item->quantity);
                } else {
                    Product::withTrashed()
                        ->whereKey($item->product_id)
                        ->increment('stock', $item->quantity);
                }
            }

            $locked->update([
                'status' => OrderStatus::Cancelled,
            ]);

            return $locked->refresh()->load('orderItems');
        });
    }

    /**
     * @param  list<array{product_id: int, product_variant_id?: int|null, quantity: int}>  $items
     * @return list<array{product_id: int, product_variant_id: int|null, quantity: int}>
     */
    private function normalizeLines(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $variantId = isset($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;
            $key = $productId.':'.($variantId ?? '0');

            if (! isset($merged[$key])) {
                $merged[$key] = [
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                    'quantity' => 0,
                ];
            }

            $merged[$key]['quantity'] += (int) $item['quantity'];

            if ($merged[$key]['quantity'] > 1000) {
                throw new OrderProcessingException('The quantity for an item may not exceed 1000.', 422);
            }
        }

        ksort($merged);

        return array_values($merged);
    }

    /**
     * @param  list<array{product_id: int, product_variant_id: int|null, quantity: int}>  $lines
     * @return array{products: Collection<int, Product>, variants: Collection<int, ProductVariant>}
     */
    private function lockInventory(array $lines): array
    {
        $productIds = [];
        $variantIds = [];

        foreach ($lines as $line) {
            $productIds[] = $line['product_id'];

            if ($line['product_variant_id'] !== null) {
                $variantIds[] = $line['product_variant_id'];
            }
        }

        $productIds = array_values(array_unique($productIds));
        sort($productIds);
        $variantIds = array_values(array_unique($variantIds));
        sort($variantIds);

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $variants = $variantIds === []
            ? collect()
            : ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

        return [
            'products' => $products,
            'variants' => $variants,
        ];
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     */
    private function lockInventoryForItems(Collection $items): void
    {
        $productIds = $items
            ->whereNull('product_variant_id')
            ->pluck('product_id')
            ->unique()
            ->sort()
            ->values();

        $variantIds = $items
            ->whereNotNull('product_variant_id')
            ->pluck('product_variant_id')
            ->unique()
            ->sort()
            ->values();

        if ($productIds->isNotEmpty()) {
            Product::withTrashed()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        if ($variantIds->isNotEmpty()) {
            ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }
    }

    /**
     * @param  list<array{product_id: int, product_variant_id: int|null, quantity: int}>  $lines
     * @param  array{products: Collection<int, Product>, variants: Collection<int, ProductVariant>}  $inventory
     * @return list<array{product_id: int, product_variant_id: int|null, product_name: string, quantity: int, unit_cents: int, subtotal_cents: int}>
     */
    private function prepareLines(array $lines, array $inventory): array
    {
        $prepared = [];

        foreach ($lines as $line) {
            $product = $inventory['products']->get($line['product_id']);

            if (! $product instanceof Product || ! $product->is_active) {
                throw new OrderProcessingException('One or more products are unavailable.', 422);
            }

            $variant = null;
            $stock = $product->stock;
            $price = (string) $product->price;

            if ($line['product_variant_id'] !== null) {
                $variant = $inventory['variants']->get($line['product_variant_id']);

                if (
                    ! $variant instanceof ProductVariant
                    || $variant->product_id !== $product->id
                    || ! $variant->is_active
                ) {
                    throw new OrderProcessingException('One or more product variants are unavailable.', 422);
                }

                $stock = $variant->stock;
                $price = $variant->price !== null ? (string) $variant->price : (string) $product->price;
            }

            if ($stock < $line['quantity']) {
                throw new OrderProcessingException('Insufficient stock for one or more items.', 409);
            }

            try {
                $unitCents = Money::toCents($price);
            } catch (InvalidArgumentException) {
                throw new OrderProcessingException('One or more products have an invalid price.', 422);
            }

            $prepared[] = [
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name' => $product->name,
                'quantity' => $line['quantity'],
                'unit_cents' => $unitCents,
                'subtotal_cents' => $unitCents * $line['quantity'],
            ];
        }

        return $prepared;
    }

    /**
     * @param  array{product_id: int, product_variant_id: int|null, quantity: int}  $line
     * @param  array{products: Collection<int, Product>, variants: Collection<int, ProductVariant>}  $inventory
     */
    private function decrementStock(array $line, array $inventory): void
    {
        if ($line['product_variant_id'] !== null) {
            $variant = $inventory['variants']->get($line['product_variant_id']);
            if (! $variant instanceof ProductVariant) {
                throw new OrderProcessingException('One or more product variants are unavailable.', 422);
            }
            $variant->decrement('stock', $line['quantity']);

            return;
        }

        $product = $inventory['products']->get($line['product_id']);
        if (! $product instanceof Product) {
            throw new OrderProcessingException('One or more products are unavailable.', 422);
        }
        $product->decrement('stock', $line['quantity']);
    }

    private function createOrderRecord(int $userId, string $subtotal): Order
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Order::query()->create([
                    'user_id' => $userId,
                    'order_number' => Order::generateUniqueNumber(),
                    'status' => OrderStatus::Pending,
                    'subtotal' => $subtotal,
                    'tax' => '0.00',
                    'total' => $subtotal,
                ]);
            } catch (QueryException $exception) {
                if (! $this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                if ($attempt === 4) {
                    throw new OrderProcessingException('Unable to create the order. Please try again.', 409);
                }
            }
        }

        throw new OrderProcessingException('Unable to create the order. Please try again.', 409);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $message = $exception->getMessage();

        return $sqlState === '23000'
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
    }
}
