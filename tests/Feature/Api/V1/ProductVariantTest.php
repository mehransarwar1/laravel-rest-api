<?php

namespace Tests\Feature\Api\V1;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductVariantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validVariantPayload(string $sku = 'VAR-BLK-001'): array
    {
        return [
            'name' => 'Black',
            'sku' => $sku,
            'price' => '19.99',
            'stock' => 8,
            'is_active' => true,
        ];
    }

    public function test_guest_can_list_variants(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->count(3)->for($product)->create();

        $this->getJson('/api/v1/products/'.$product->id.'/variants')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_variant_list_is_paginated_and_searchable(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->for($product)->create(['name' => 'Large', 'sku' => 'VAR-L-001']);
        ProductVariant::factory()->for($product)->create(['name' => 'Small', 'sku' => 'VAR-S-001']);

        $this->getJson('/api/v1/products/'.$product->id.'/variants?per_page=1&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/products/'.$product->id.'/variants?search=Large')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'VAR-L-001');
    }

    public function test_variant_sort_whitelist_works(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->for($product)->create(['name' => 'Cheap', 'sku' => 'VAR-A-001', 'price' => '5.00']);
        ProductVariant::factory()->for($product)->create(['name' => 'Dear', 'sku' => 'VAR-B-001', 'price' => '15.00']);

        $this->getJson('/api/v1/products/'.$product->id.'/variants?sort=price')
            ->assertOk()
            ->assertJsonPath('data.0.price', '5.00');

        $this->getJson('/api/v1/products/'.$product->id.'/variants?sort=users.password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_guest_can_show_variant(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        $this->getJson('/api/v1/products/'.$product->id.'/variants/'.$variant->id)
            ->assertOk()
            ->assertJsonPath('data.id', $variant->id)
            ->assertJsonMissingPath('data.product');
    }

    public function test_variant_from_another_product_returns_not_found(): void
    {
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $variant = ProductVariant::factory()->for($other)->create();

        $this->getJson('/api/v1/products/'.$product->id.'/variants/'.$variant->id)
            ->assertNotFound();
    }

    public function test_inactive_and_deleted_products_do_not_expose_variants(): void
    {
        $inactive = Product::factory()->inactive()->create();
        $inactiveVariant = ProductVariant::factory()->for($inactive)->create();

        $this->getJson('/api/v1/products/'.$inactive->id.'/variants')->assertNotFound();
        $this->getJson('/api/v1/products/'.$inactive->id.'/variants/'.$inactiveVariant->id)->assertNotFound();

        $deleted = Product::factory()->create();
        $deletedVariant = ProductVariant::factory()->for($deleted)->create();
        $deleted->delete();

        $this->getJson('/api/v1/products/'.$deleted->id.'/variants')->assertNotFound();
        $this->getJson('/api/v1/products/'.$deleted->id.'/variants/'.$deletedVariant->id)->assertNotFound();
    }

    public function test_guest_cannot_create_variant(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/api/v1/products/'.$product->id.'/variants', $this->validVariantPayload())
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_variant(): void
    {
        $product = Product::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/products/'.$product->id.'/variants', $this->validVariantPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Black')
            ->assertJsonPath('data.sku', 'VAR-BLK-001')
            ->assertJsonPath('data.price', '19.99');

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'sku' => 'VAR-BLK-001',
        ]);
    }

    public function test_product_id_cannot_be_injected_on_create_or_update(): void
    {
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $payload = $this->validVariantPayload();
        $payload['product_id'] = $other->id;

        $response = $this->postJson('/api/v1/products/'.$product->id.'/variants', $payload)
            ->assertCreated();

        $this->assertSame($product->id, ProductVariant::query()->findOrFail($response->json('data.id'))->product_id);

        $variant = ProductVariant::factory()->for($product)->create();
        $this->putJson('/api/v1/products/'.$product->id.'/variants/'.$variant->id, [
            'name' => 'Updated',
            'product_id' => $other->id,
        ])->assertOk();

        $this->assertSame($product->id, $variant->fresh()->product_id);
        $this->assertSame('Updated', $variant->fresh()->name);
    }

    public function test_invalid_and_duplicate_sku_are_rejected(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->for($product)->create(['sku' => 'VAR-BLK-001']);
        Sanctum::actingAs(User::factory()->create());

        $payload = $this->validVariantPayload('bad sku');
        $this->postJson('/api/v1/products/'.$product->id.'/variants', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);

        $this->postJson('/api/v1/products/'.$product->id.'/variants', $this->validVariantPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
    }

    public function test_negative_stock_and_price_are_rejected(): void
    {
        $product = Product::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $payload = $this->validVariantPayload();
        $payload['stock'] = -1;
        $this->postJson('/api/v1/products/'.$product->id.'/variants', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock']);

        $payload = $this->validVariantPayload('VAR-NEG-001');
        $payload['price'] = '-5.00';
        $this->postJson('/api/v1/products/'.$product->id.'/variants', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);
    }

    public function test_authenticated_user_can_update_variant(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['sku' => 'VAR-OLD-001']);
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/v1/products/'.$product->id.'/variants/'.$variant->id, [
            'name' => 'White',
            'stock' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'White')
            ->assertJsonPath('data.sku', 'VAR-OLD-001')
            ->assertJsonPath('data.stock', 3);
    }

    public function test_variant_without_order_items_can_be_deleted(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/v1/products/'.$product->id.'/variants/'.$variant->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('product_variants', ['id' => $variant->id]);
    }

    public function test_variant_referenced_by_order_cannot_be_deleted(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create();
        OrderItem::factory()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/v1/products/'.$product->id.'/variants/'.$variant->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id]);
        $this->assertDatabaseHas('order_items', ['product_variant_id' => $variant->id]);
    }

    public function test_guest_cannot_update_or_delete_variant(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        $this->putJson('/api/v1/products/'.$product->id.'/variants/'.$variant->id, ['name' => 'Nope'])
            ->assertUnauthorized();
        $this->deleteJson('/api/v1/products/'.$product->id.'/variants/'.$variant->id)
            ->assertUnauthorized();
    }
}
