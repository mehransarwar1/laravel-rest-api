<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Electronics',
                'slug' => 'electronics',
                'description' => 'Demo electronics and accessories.',
            ],
            [
                'name' => 'Home & Kitchen',
                'slug' => 'home-kitchen',
                'description' => 'Demo home and kitchen products.',
            ],
            [
                'name' => 'Books',
                'slug' => 'books',
                'description' => 'Demo books and reading materials.',
            ],
            [
                'name' => 'Sports & Outdoors',
                'slug' => 'sports-outdoors',
                'description' => 'Demo sports and outdoor gear.',
            ],
            [
                'name' => 'Clothing',
                'slug' => 'clothing',
                'description' => 'Demo apparel and accessories.',
            ],
        ];

        foreach ($categories as $category) {
            Category::query()->create([
                ...$category,
                'is_active' => true,
            ]);
        }
    }
}
