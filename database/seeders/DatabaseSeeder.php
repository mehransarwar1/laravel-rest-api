<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Demo users use the factory default password "password" (development only).
        User::factory()->create([
            'name' => 'Demo User',
            'email' => 'test@example.com',
        ]);

        User::factory()->create([
            'name' => 'Alex Rivera',
            'email' => 'alex.rivera@example.com',
        ]);

        User::factory()->create([
            'name' => 'Jordan Lee',
            'email' => 'jordan.lee@example.com',
        ]);

        User::factory()->create([
            'name' => 'Sam Patel',
            'email' => 'sam.patel@example.com',
        ]);

        $this->call([
            CategorySeeder::class,
            ProductSeeder::class,
            OrderSeeder::class,
        ]);
    }
}
