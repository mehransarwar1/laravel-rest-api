<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $catalog = [
            'electronics' => [
                ['name' => 'Demo Wireless Mouse', 'price' => '24.99', 'sku' => 'PRD-MOUSE-001'],
                ['name' => 'Demo USB-C Hub', 'price' => '39.50', 'sku' => 'PRD-HUB-001'],
                ['name' => 'Demo Bluetooth Speaker', 'price' => '59.00', 'sku' => 'PRD-SPEAK-001'],
                ['name' => 'Demo Webcam', 'price' => '79.99', 'sku' => 'PRD-CAM-001'],
            ],
            'home-kitchen' => [
                ['name' => 'Demo Ceramic Mug', 'price' => '12.00', 'sku' => 'PRD-MUG-001'],
                ['name' => 'Demo Kettle', 'price' => '34.99', 'sku' => 'PRD-KETL-001'],
                ['name' => 'Demo Cutting Board', 'price' => '18.50', 'sku' => 'PRD-BOARD-001'],
                ['name' => 'Demo Desk Lamp', 'price' => '29.00', 'sku' => 'PRD-LAMP-001'],
            ],
            'books' => [
                ['name' => 'Demo Laravel Handbook', 'price' => '29.99', 'sku' => 'PRD-BOOK-001'],
                ['name' => 'Demo PHP Cookbook', 'price' => '32.00', 'sku' => 'PRD-BOOK-002'],
                ['name' => 'Demo API Design Notes', 'price' => '19.99', 'sku' => 'PRD-BOOK-003'],
                ['name' => 'Demo Testing Primer', 'price' => '22.50', 'sku' => 'PRD-BOOK-004'],
            ],
            'sports-outdoors' => [
                ['name' => 'Demo Yoga Mat', 'price' => '25.00', 'sku' => 'PRD-YOGA-001'],
                ['name' => 'Demo Water Bottle', 'price' => '14.99', 'sku' => 'PRD-BTTL-001'],
                ['name' => 'Demo Jump Rope', 'price' => '9.99', 'sku' => 'PRD-ROPE-001'],
                ['name' => 'Demo Hiking Cap', 'price' => '16.00', 'sku' => 'PRD-CAP-001'],
            ],
            'clothing' => [
                ['name' => 'Demo Cotton T-Shirt', 'price' => '19.99', 'sku' => 'PRD-SHIRT-001'],
                ['name' => 'Demo Hoodie', 'price' => '44.00', 'sku' => 'PRD-HOOD-001'],
                ['name' => 'Demo Crew Socks', 'price' => '8.50', 'sku' => 'PRD-SOCK-001'],
                ['name' => 'Demo Canvas Tote', 'price' => '15.00', 'sku' => 'PRD-TOTE-001'],
            ],
        ];

        foreach ($catalog as $slug => $products) {
            $category = Category::query()->where('slug', $slug)->firstOrFail();

            foreach ($products as $productData) {
                $product = Product::query()->create([
                    'category_id' => $category->id,
                    'name' => $productData['name'],
                    'slug' => Str::slug($productData['name']),
                    'description' => 'Demo product for local development only.',
                    'sku' => $productData['sku'],
                    'price' => $productData['price'],
                    'stock' => 50,
                    'is_active' => true,
                ]);

                $this->createVariants($product);
            }
        }
    }

    private function createVariants(Product $product): void
    {
        $variantSets = [
            [
                ['name' => 'Black', 'suffix' => 'BLK'],
                ['name' => 'White', 'suffix' => 'WHT'],
            ],
            [
                ['name' => 'Small', 'suffix' => 'S'],
                ['name' => 'Medium', 'suffix' => 'M'],
                ['name' => 'Large', 'suffix' => 'L'],
            ],
            [
                ['name' => 'Standard', 'suffix' => 'STD'],
            ],
        ];

        $variants = fake()->randomElement($variantSets);

        foreach ($variants as $variant) {
            ProductVariant::query()->create([
                'product_id' => $product->id,
                'name' => $variant['name'],
                'sku' => $product->sku.'-'.$variant['suffix'],
                'price' => null,
                'stock' => 20,
                'is_active' => true,
            ]);
        }
    }
}
