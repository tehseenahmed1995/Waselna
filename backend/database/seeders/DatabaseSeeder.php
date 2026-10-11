<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The entry point of the documented seed command
 * (`php artisan migrate --seed`, `php artisan db:seed`) of the
 * Local_Dev_Environment (Requirement 24.7).
 *
 * The demonstration data itself — at least 3 Restaurants, 5 Restaurant_Branches
 * with resolved coordinates and assigned Delivery_Zones, 20 Menu_Items,
 * 5 Product_Variants, one Platform user per Role, and the default Administrator
 * read from SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD — is produced by the
 * demonstration data seeder, which the Pricing module task registers here as a
 * `$this->call(...)` once the owning modules' write paths exist. Until then the
 * command is wired and succeeds against the current schema without creating
 * anything.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
