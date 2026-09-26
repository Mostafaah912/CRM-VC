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
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            // PRD §17's 12 seed segments (11 built — see ARCHITECTURE.md, P5-07): real product data, not
            // demo data, so unlike DemoDataSeeder below this runs in every environment, production included.
            DefaultSegmentSeeder::class,
        ]);

        // Fake customers/orders: never in production, where real data comes only from the Woo sync.
        if (! app()->isProduction()) {
            $this->call(DemoDataSeeder::class);
        }

        // User::factory(10)->create();

        // Idempotent so `php artisan db:seed` can be re-run on a seeded database.
        if (! User::query()->where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}
