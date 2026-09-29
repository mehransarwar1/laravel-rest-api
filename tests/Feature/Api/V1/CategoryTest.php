<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_list_categories(): void
    {
        Category::factory()->count(3)->create();

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Categories retrieved successfully.')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_category_list_is_paginated(): void
    {
        Category::factory()->count(8)->create();

        $this->getJson('/api/v1/categories?per_page=5&page=2')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 8);
    }

    public function test_per_page_cannot_exceed_maximum(): void
    {
        $this->getJson('/api/v1/categories?per_page=999999')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_categories_can_be_searched_by_name_and_slug(): void
    {
        Category::factory()->create(['name' => 'Electronics', 'slug' => 'electronics']);
        Category::factory()->create(['name' => 'Books', 'slug' => 'books']);

        $this->getJson('/api/v1/categories?search=electro')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'electronics');
    }

    public function test_search_rejects_overlong_input(): void
    {
        $this->getJson('/api/v1/categories?search='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search']);
    }

    public function test_search_sql_payload_does_not_error(): void
    {
        Category::factory()->create(['name' => 'Electronics', 'slug' => 'electronics']);

        $this->getJson('/api/v1/categories?search='.urlencode("electronics' OR 1=1 --"))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_categories_can_be_filtered_by_active_status(): void
    {
        Category::factory()->create(['name' => 'Live', 'is_active' => true]);
        Category::factory()->inactive()->create(['name' => 'Hidden']);

        $this->getJson('/api/v1/categories?is_active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Live');

        $this->getJson('/api/v1/categories?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Hidden');
    }

    public function test_invalid_active_filter_is_rejected(): void
    {
        $this->getJson('/api/v1/categories?is_active=maybe')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);
    }

    public function test_categories_can_be_sorted_ascending_and_descending(): void
    {
        Category::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        Category::factory()->create(['name' => 'Zulu', 'slug' => 'zulu']);

        $this->getJson('/api/v1/categories?sort=name')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Alpha')
            ->assertJsonPath('data.1.name', 'Zulu');

        $this->getJson('/api/v1/categories?sort=-name')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Zulu')
            ->assertJsonPath('data.1.name', 'Alpha');
    }

    public function test_invalid_sort_field_is_rejected(): void
    {
        $this->getJson('/api/v1/categories?sort=users.password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);

        $this->getJson('/api/v1/categories?sort=(SELECT%201)')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_deleted_categories_are_not_listed(): void
    {
        $category = Category::factory()->create();
        $category->delete();

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_guest_can_view_a_category(): void
    {
        $category = Category::factory()->create([
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        $this->getJson('/api/v1/categories/'.$category->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.slug', 'electronics')
            ->assertJsonMissingPath('data.deleted_at');
    }

    public function test_missing_category_returns_not_found(): void
    {
        $this->getJson('/api/v1/categories/999999')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Resource not found.');
    }

    public function test_deleted_category_is_not_shown(): void
    {
        $category = Category::factory()->create();
        $category->delete();

        $this->getJson('/api/v1/categories/'.$category->id)
            ->assertNotFound();
    }

    public function test_guest_cannot_create_category(): void
    {
        $this->postJson('/api/v1/categories', [
            'name' => 'Electronics',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_category(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/categories', [
            'name' => 'Home Appliances',
            'description' => 'Kitchen and home goods',
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Home Appliances')
            ->assertJsonPath('data.slug', 'home-appliances')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.deleted_at');

        $this->assertDatabaseHas('categories', [
            'name' => 'Home Appliances',
            'slug' => 'home-appliances',
        ]);
    }

    public function test_duplicate_generated_slugs_are_made_unique(): void
    {
        Category::factory()->create([
            'name' => 'Home Appliances',
            'slug' => 'home-appliances',
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/categories', [
            'name' => 'Home Appliances',
        ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'home-appliances-2');
    }

    public function test_create_category_validation_works(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/categories', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        Category::factory()->create(['slug' => 'electronics']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/categories', [
            'name' => 'Gadgets',
            'slug' => 'electronics',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_create_ignores_mass_assignment_of_protected_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/categories', [
            'name' => 'Electronics',
            'id' => 999,
            'created_at' => '2000-01-01T00:00:00+00:00',
            'deleted_at' => now()->toIso8601String(),
        ])->assertCreated();

        $category = Category::query()->findOrFail($response->json('data.id'));

        $this->assertNotSame(999, $category->id);
        $this->assertNotSame('2000-01-01', $category->created_at?->toDateString());
        $this->assertNull($category->deleted_at);
    }

    public function test_guest_cannot_update_category(): void
    {
        $category = Category::factory()->create();

        $this->putJson('/api/v1/categories/'.$category->id, [
            'name' => 'Updated',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_update_category(): void
    {
        $category = Category::factory()->create([
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/categories/'.$category->id, [
            'name' => 'Consumer Electronics',
            'slug' => 'electronics',
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Consumer Electronics')
            ->assertJsonPath('data.slug', 'electronics')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_update_rejects_duplicate_slug(): void
    {
        Category::factory()->create(['slug' => 'books']);
        $category = Category::factory()->create(['slug' => 'electronics']);
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/categories/'.$category->id, [
            'slug' => 'books',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_update_ignores_protected_fields(): void
    {
        $category = Category::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $originalCreatedAt = $category->created_at?->toIso8601String();

        $this->putJson('/api/v1/categories/'.$category->id, [
            'name' => 'Updated',
            'id' => 999,
            'created_at' => '2000-01-01T00:00:00+00:00',
            'deleted_at' => now()->toIso8601String(),
        ])->assertOk();

        $category->refresh();

        $this->assertNotSame(999, $category->id);
        $this->assertSame($originalCreatedAt, $category->created_at?->toIso8601String());
        $this->assertNull($category->deleted_at);
        $this->assertSame('Updated', $category->name);
    }

    public function test_patch_updates_only_supplied_fields(): void
    {
        $category = Category::factory()->create([
            'name' => 'Electronics',
            'slug' => 'electronics',
            'description' => 'Original description',
            'is_active' => true,
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/v1/categories/'.$category->id, [
            'description' => 'Updated description',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Electronics')
            ->assertJsonPath('data.slug', 'electronics')
            ->assertJsonPath('data.description', 'Updated description')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_guest_cannot_delete_category(): void
    {
        $category = Category::factory()->create();

        $this->deleteJson('/api/v1/categories/'.$category->id)
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_soft_delete_empty_category(): void
    {
        $category = Category::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/v1/categories/'.$category->id)
            ->assertNoContent();

        $this->assertSoftDeleted($category);
        $this->getJson('/api/v1/categories/'.$category->id)->assertNotFound();
        $this->getJson('/api/v1/categories')->assertJsonCount(0, 'data');
    }

    public function test_category_with_products_cannot_be_deleted(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/v1/categories/'.$category->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Category cannot be deleted because it contains products.');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'category_id' => $category->id,
        ]);
    }
}
