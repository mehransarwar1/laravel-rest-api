<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validProductPayload(?Category $category = null): array
    {
        $category ??= Category::factory()->create();

        return [
            'name' => 'Demo Laptop',
            'description' => 'A demo laptop',
            'category_id' => $category->id,
            'sku' => 'PRD-LAPTOP-001',
            'price' => '999.99',
            'stock' => 10,
            'is_active' => true,
        ];
    }

    public function test_guest_can_list_products(): void
    {
        Product::factory()->count(3)->create();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonMissingPath('data.0.deleted_at');
    }

    public function test_product_list_is_paginated(): void
    {
        Product::factory()->count(8)->create();

        $this->getJson('/api/v1/products?per_page=5&page=2')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 8);
    }

    public function test_per_page_cannot_exceed_maximum(): void
    {
        $this->getJson('/api/v1/products?per_page=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_products_can_be_searched(): void
    {
        Product::factory()->create(['name' => 'Gaming Laptop', 'slug' => 'gaming-laptop', 'sku' => 'PRD-GAME-001']);
        Product::factory()->create(['name' => 'Office Chair', 'slug' => 'office-chair', 'sku' => 'PRD-CHAIR-001']);

        $this->getJson('/api/v1/products?search=laptop')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'PRD-GAME-001');
    }

    public function test_search_sql_payload_does_not_error(): void
    {
        Product::factory()->create(['name' => 'Laptop', 'slug' => 'laptop', 'sku' => 'PRD-LAP-001']);

        $this->getJson('/api/v1/products?search='.urlencode("laptop' OR 1=1 --"))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_products_can_be_filtered_by_category(): void
    {
        $electronics = Category::factory()->create();
        $books = Category::factory()->create();
        Product::factory()->for($electronics)->create();
        Product::factory()->for($books)->create();

        $this->getJson('/api/v1/products?category_id='.$electronics->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category.id', $electronics->id);
    }

    public function test_deleted_category_filter_is_rejected(): void
    {
        $category = Category::factory()->create();
        $category->delete();

        $this->getJson('/api/v1/products?category_id='.$category->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_products_can_be_filtered_by_active_status(): void
    {
        Product::factory()->create(['name' => 'Live']);
        Product::factory()->inactive()->create(['name' => 'Hidden']);

        $this->getJson('/api/v1/products?is_active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Live');
    }

    public function test_products_can_be_filtered_by_price_range(): void
    {
        Product::factory()->create(['price' => '50.00']);
        Product::factory()->create(['price' => '150.00']);
        Product::factory()->create(['price' => '300.00']);

        $this->getJson('/api/v1/products?min_price=100&max_price=200')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.price', '150.00');
    }

    public function test_invalid_price_range_is_rejected(): void
    {
        $this->getJson('/api/v1/products?min_price=200&max_price=100')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['min_price']);
    }

    public function test_products_can_be_filtered_by_stock(): void
    {
        Product::factory()->create(['stock' => 5]);
        Product::factory()->outOfStock()->create();

        $this->getJson('/api/v1/products?in_stock=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_products_can_be_sorted(): void
    {
        Product::factory()->create(['name' => 'Alpha', 'price' => '10.00']);
        Product::factory()->create(['name' => 'Zulu', 'price' => '20.00']);

        $this->getJson('/api/v1/products?sort=price')
            ->assertOk()
            ->assertJsonPath('data.0.price', '10.00')
            ->assertJsonPath('data.1.price', '20.00');

        $this->getJson('/api/v1/products?sort=-price')
            ->assertOk()
            ->assertJsonPath('data.0.price', '20.00')
            ->assertJsonPath('data.1.price', '10.00');
    }

    public function test_invalid_sort_is_rejected(): void
    {
        $this->getJson('/api/v1/products?sort=users.password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);

        $this->getJson('/api/v1/products?sort=(SELECT%201)')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_product_list_eager_loads_categories(): void
    {
        $category = Category::factory()->create();
        Product::factory()->count(5)->for($category)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(5, 'data');

        $categoryQueries = collect(DB::getQueryLog())->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'from "categories"') || str_contains($sql, 'from `categories`');
        });

        $this->assertLessThanOrEqual(1, $categoryQueries->count());
    }

    public function test_guest_can_view_a_product(): void
    {
        $product = Product::factory()->create();

        $this->getJson('/api/v1/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.category.id', $product->category_id)
            ->assertJsonMissingPath('data.variants')
            ->assertJsonMissingPath('data.deleted_at');
    }

    public function test_missing_product_returns_not_found(): void
    {
        $this->getJson('/api/v1/products/999999')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_soft_deleted_product_is_not_exposed(): void
    {
        $product = Product::factory()->create();
        $product->delete();

        $this->getJson('/api/v1/products/'.$product->id)->assertNotFound();
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
    }

    public function test_guest_cannot_create_product(): void
    {
        $this->postJson('/api/v1/products', $this->validProductPayload())
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_product(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/products', $this->validProductPayload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Demo Laptop')
            ->assertJsonPath('data.slug', 'demo-laptop')
            ->assertJsonPath('data.price', '999.99');
    }

    public function test_create_product_validation_works(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/products', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'category_id', 'price', 'stock']);
    }

    public function test_invalid_category_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $payload = $this->validProductPayload();
        $payload['category_id'] = 999999;

        $this->postJson('/api/v1/products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_inactive_and_deleted_categories_are_rejected_on_create(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $inactive = Category::factory()->inactive()->create();
        $payload = $this->validProductPayload($inactive);
        $this->postJson('/api/v1/products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);

        $deleted = Category::factory()->create();
        $deleted->delete();
        $payload = $this->validProductPayload();
        $payload['category_id'] = $deleted->id;
        $this->postJson('/api/v1/products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_invalid_price_and_negative_stock_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $payload = $this->validProductPayload();

        $payload['price'] = '19.999';
        $this->postJson('/api/v1/products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);

        $payload['price'] = '-1.00';
        $this->postJson('/api/v1/products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);

        $payload['price'] = '99999999999.99';
        $this->postJson('/api/v1/products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);

        $payload = $this->validProductPayload();
        $payload['stock'] = -1;
        $this->postJson('/api/v1/products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock']);
    }

    public function test_duplicate_sku_is_rejected(): void
    {
        Product::factory()->create(['sku' => 'PRD-LAPTOP-001']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/products', $this->validProductPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
    }

    public function test_create_ignores_mass_assignment_of_protected_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $payload = $this->validProductPayload();
        $payload['id'] = 999;
        $payload['deleted_at'] = now()->toIso8601String();

        $response = $this->postJson('/api/v1/products', $payload)->assertCreated();
        $product = Product::query()->findOrFail($response->json('data.id'));

        $this->assertNotSame(999, $product->id);
        $this->assertNull($product->deleted_at);
    }

    public function test_authenticated_user_can_update_and_patch_product(): void
    {
        $product = Product::factory()->create(['sku' => 'PRD-OLD-001', 'stock' => 5]);
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/products/'.$product->id, [
            'name' => 'Updated Laptop',
            'sku' => 'PRD-OLD-001',
            'price' => '50.00',
            'stock' => 8,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Laptop')
            ->assertJsonPath('data.sku', 'PRD-OLD-001');

        $this->patchJson('/api/v1/products/'.$product->id, [
            'stock' => 12,
        ])
            ->assertOk()
            ->assertJsonPath('data.stock', 12)
            ->assertJsonPath('data.name', 'Updated Laptop');
    }

    public function test_update_rejects_duplicate_sku_and_negative_stock(): void
    {
        Product::factory()->create(['sku' => 'PRD-TAKEN-001']);
        $product = Product::factory()->create(['sku' => 'PRD-MINE-001']);
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/products/'.$product->id, ['sku' => 'PRD-TAKEN-001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);

        $this->patchJson('/api/v1/products/'.$product->id, ['stock' => -5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock']);
    }

    public function test_update_ignores_protected_fields(): void
    {
        $product = Product::factory()->create();
        $originalId = $product->id;
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/products/'.$product->id, [
            'name' => 'Safe Update',
            'id' => 999,
            'deleted_at' => now()->toIso8601String(),
        ])->assertOk();

        $product->refresh();
        $this->assertSame($originalId, $product->id);
        $this->assertNull($product->deleted_at);
        $this->assertSame('Safe Update', $product->name);
    }

    public function test_authenticated_user_can_soft_delete_product(): void
    {
        $product = Product::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/v1/products/'.$product->id)->assertNoContent();
        $this->assertSoftDeleted($product);
        $this->getJson('/api/v1/products/'.$product->id)->assertNotFound();
    }

    public function test_guest_cannot_update_or_delete_product(): void
    {
        $product = Product::factory()->create();

        $this->putJson('/api/v1/products/'.$product->id, ['name' => 'Nope'])->assertUnauthorized();
        $this->deleteJson('/api/v1/products/'.$product->id)->assertUnauthorized();
    }
}
