<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function item(Product $product, int $quantity = 1, ?ProductVariant $variant = null): array
    {
        $payload = [
            'product_id' => $product->id,
            'quantity' => $quantity,
        ];

        if ($variant !== null) {
            $payload['product_variant_id'] = $variant->id;
        }

        return $payload;
    }

    public function test_guest_cannot_access_order_endpoints(): void
    {
        $order = Order::factory()->create();

        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->getJson('/api/v1/orders/'.$order->id)->assertUnauthorized();
        $this->postJson('/api/v1/orders', ['items' => []])->assertUnauthorized();
        $this->patchJson('/api/v1/orders/'.$order->id)->assertUnauthorized();
        $this->deleteJson('/api/v1/orders/'.$order->id)->assertUnauthorized();
    }

    public function test_user_only_sees_own_orders(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $own = Order::factory()->for($user)->create();
        Order::factory()->for($other)->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_user_cannot_view_another_users_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertNotFound();
    }

    public function test_user_cannot_cancel_another_users_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/orders/'.$order->id)->assertNotFound();
    }

    public function test_user_cannot_patch_another_users_or_guessed_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/orders/'.$order->id, ['status' => 'completed'])->assertNotFound();
        $this->getJson('/api/v1/orders/999999')->assertNotFound();
        $this->deleteJson('/api/v1/orders/999999')->assertNotFound();
    }

    public function test_order_list_is_paginated_and_filterable(): void
    {
        $user = User::factory()->create();
        Order::factory()->count(3)->for($user)->create(['status' => OrderStatus::Pending]);
        Order::factory()->for($user)->status(OrderStatus::Completed)->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/orders?per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4);

        $this->getJson('/api/v1/orders?status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'completed');
    }

    public function test_invalid_list_parameters_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/orders?per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/orders?sort=users.password')->assertUnprocessable()->assertJsonValidationErrors(['sort']);
        $this->getJson('/api/v1/orders?status=bogus')->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->getJson('/api/v1/orders?from_date=not-a-date')->assertUnprocessable()->assertJsonValidationErrors(['from_date']);
        $this->getJson('/api/v1/orders?from_date=2026-02-02&to_date=2026-02-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from_date']);
    }

    public function test_authenticated_user_can_create_order(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => '10.00', 'stock' => 5, 'name' => 'Demo Mug']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [$this->item($product, 2)],
            'user_id' => 999,
            'status' => 'completed',
            'subtotal' => '1.00',
            'total' => '1.00',
            'order_number' => 'HACKED',
            'unit_price' => '0.01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subtotal', '20.00')
            ->assertJsonPath('data.total', '20.00')
            ->assertJsonPath('data.items.0.product_name', 'Demo Mug')
            ->assertJsonPath('data.items.0.unit_price', '10.00')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.subtotal', '20.00');

        $this->assertNotSame('HACKED', $response->json('data.order_number'));
        $this->assertSame($user->id, Order::query()->findOrFail($response->json('data.id'))->user_id);
        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_variant_price_overrides_product_price(): void
    {
        $product = Product::factory()->create(['price' => '10.00', 'stock' => 10]);
        $variant = ProductVariant::factory()->for($product)->create(['price' => '15.50', 'stock' => 4]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/orders', [
            'items' => [$this->item($product, 2, $variant)],
        ])
            ->assertCreated()
            ->assertJsonPath('data.total', '31.00')
            ->assertJsonPath('data.items.0.unit_price', '15.50');

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(2, $variant->fresh()->stock);
    }

    public function test_null_variant_price_falls_back_to_product_price(): void
    {
        $product = Product::factory()->create(['price' => '12.00', 'stock' => 5]);
        $variant = ProductVariant::factory()->for($product)->create(['price' => null, 'stock' => 5]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/orders', [
            'items' => [$this->item($product, 1, $variant)],
        ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '12.00')
            ->assertJsonPath('data.total', '12.00');
    }

    public function test_order_snapshots_survive_product_changes(): void
    {
        $product = Product::factory()->create(['name' => 'Original Name', 'price' => '9.00', 'stock' => 3]);
        Sanctum::actingAs(User::factory()->create());

        $orderId = $this->postJson('/api/v1/orders', [
            'items' => [$this->item($product, 1)],
        ])->assertCreated()->json('data.id');

        $product->update(['name' => 'New Name', 'price' => '99.00']);

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.items.0.product_name', 'Original Name')
            ->assertJsonPath('data.items.0.unit_price', '9.00');
    }

    public function test_duplicate_lines_are_merged_before_stock_check(): void
    {
        $product = Product::factory()->create(['price' => '5.00', 'stock' => 10]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/orders', [
            'items' => [
                $this->item($product, 2),
                $this->item($product, 3),
            ],
        ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 5)
            ->assertJsonPath('data.total', '25.00');

        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_product_and_variant_lines_remain_distinct(): void
    {
        $product = Product::factory()->create(['price' => '10.00', 'stock' => 5]);
        $variant = ProductVariant::factory()->for($product)->create(['price' => '8.00', 'stock' => 5]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/orders', [
            'items' => [
                $this->item($product, 1),
                $this->item($product, 1, $variant),
            ],
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.items');

        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame(4, $variant->fresh()->stock);
    }

    public function test_insufficient_stock_fails_without_partial_updates(): void
    {
        $available = Product::factory()->create(['price' => '10.00', 'stock' => 5]);
        $scarce = Product::factory()->create(['price' => '10.00', 'stock' => 1]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/orders', [
            'items' => [
                $this->item($available, 1),
                $this->item($scarce, 2),
            ],
        ])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame(5, $available->fresh()->stock);
        $this->assertSame(1, $scarce->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_inactive_and_deleted_products_cannot_be_ordered(): void
    {
        $inactive = Product::factory()->inactive()->create(['stock' => 5]);
        $deleted = Product::factory()->create(['stock' => 5]);
        $deletedId = $deleted->id;
        $deleted->delete();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/orders', [
            'items' => [$this->item($inactive, 1)],
        ])->assertUnprocessable();

        $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $deletedId, 'quantity' => 1]],
        ])->assertUnprocessable();

        $this->assertSame(5, $inactive->fresh()->stock);
    }

    public function test_inactive_and_mismatched_variants_cannot_be_ordered(): void
    {
        $product = Product::factory()->create(['stock' => 5]);
        $other = Product::factory()->create(['stock' => 5]);
        $inactiveVariant = ProductVariant::factory()->for($product)->inactive()->create(['stock' => 5]);
        $foreignVariant = ProductVariant::factory()->for($other)->create(['stock' => 5]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/orders', [
            'items' => [$this->item($product, 1, $inactiveVariant)],
        ])->assertUnprocessable();

        $this->postJson('/api/v1/orders', [
            'items' => [$this->item($product, 1, $foreignVariant)],
        ])->assertUnprocessable();

        $this->assertSame(5, $inactiveVariant->fresh()->stock);
        $this->assertSame(5, $foreignVariant->fresh()->stock);
    }

    public function test_create_order_validation(): void
    {
        $product = Product::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/orders', [])->assertUnprocessable()->assertJsonValidationErrors(['items']);
        $this->postJson('/api/v1/orders', ['items' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors(['items']);
        $this->postJson('/api/v1/orders', ['items' => []])->assertUnprocessable()->assertJsonValidationErrors(['items']);
        $this->postJson('/api/v1/orders', ['items' => [['product_id' => 999999, 'quantity' => 1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_id']);
        $this->postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 0]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity']);
        $this->postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1001]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity']);
        $this->postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'product_variant_id' => 999999, 'quantity' => 1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_variant_id']);
    }

    public function test_owner_can_view_order_without_n_plus_one(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        OrderItem::factory()->count(3)->for($order)->create();
        Sanctum::actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonMissingPath('data.user');

        $itemQueries = collect(DB::getQueryLog())->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'from "order_items"') || str_contains($sql, 'from `order_items`');
        });

        $this->assertLessThanOrEqual(1, $itemQueries->count());
    }

    public function test_patch_is_not_allowed(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/orders/'.$order->id, ['status' => 'completed'])
            ->assertStatus(405);
    }

    public function test_pending_order_can_be_cancelled_and_stock_restored_once(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => '10.00', 'stock' => 5]);
        Sanctum::actingAs($user);

        $orderId = $this->postJson('/api/v1/orders', [
            'items' => [$this->item($product, 2)],
        ])->assertCreated()->json('data.id');

        $this->assertSame(3, $product->fresh()->stock);

        $this->deleteJson('/api/v1/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonCount(1, 'data.items');

        $this->assertSame(5, $product->fresh()->stock);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'cancelled']);
        $this->assertDatabaseCount('order_items', 1);

        $this->deleteJson('/api/v1/orders/'.$orderId)
            ->assertStatus(409);

        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_completed_order_cannot_be_cancelled(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 5]);
        $order = Order::factory()->for($user)->status(OrderStatus::Completed)->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/orders/'.$order->id)->assertStatus(409);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }
}
