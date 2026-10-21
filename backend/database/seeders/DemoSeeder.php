<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\User;
use App\Modules\Catalog\Models\MenuCategory;
use App\Modules\Catalog\Models\MenuItem;
use App\Modules\Catalog\Models\Restaurant;
use App\Modules\Catalog\Models\RestaurantBranch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demonstration data seeder (task 12.7, initial slice).
 *
 * Seeds the default Administrator from SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD and
 * one Platform user per Role, idempotently by `email_lower`. The password is
 * stored only as a salted adaptive hash, the same write path as registration.
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $adminEmail = (string) env('SEED_ADMIN_EMAIL', 'admin@waselna.local');
        $adminPassword = (string) env('SEED_ADMIN_PASSWORD', 'local-seed-password');

        // Restaurants first, so the operator/staff can be linked to one.
        $bella = $this->ensureRestaurant('Bella Napoli', 'Wood-fired pizza and pasta.', 'Italian', [
            ['Margherita Pizza', 'Tomato, mozzarella, basil.', 1200],
            ['Spaghetti Carbonara', 'Egg, pecorino, guanciale.', 1400],
            ['Tiramisu', 'Classic coffee dessert.', 600],
        ]);
        $this->ensureRestaurant('Sakura Sushi', 'Fresh sushi and ramen.', 'Japanese', [
            ['Salmon Nigiri (2 pcs)', 'Fresh salmon over rice.', 700],
            ['Tonkotsu Ramen', 'Rich pork-bone broth.', 1500],
            ['Edamame', 'Steamed soybeans with salt.', 400],
        ]);
        $this->ensureRestaurant('Taco Fiesta', 'Street-style Mexican tacos.', 'Mexican', [
            ['Carne Asada Taco', 'Grilled beef, onion, cilantro.', 350],
            ['Chicken Burrito', 'Rice, beans, chicken, salsa.', 950],
            ['Churros', 'Cinnamon sugar, chocolate dip.', 500],
        ]);

        $branchId = RestaurantBranch::query()->where('restaurant_id', $bella->id)->value('id');

        $this->ensureUser($adminEmail, $adminPassword, 'Platform Administrator', 'administrator');
        $this->ensureUser('customer@waselna.local', $adminPassword, 'Demo Customer', 'customer');
        // The operator and staff belong to Bella Napoli so their order queue is scoped.
        $this->ensureUser('operator@waselna.local', $adminPassword, 'Demo Operator', 'restaurant_operator', $bella->id, $branchId);
        $this->ensureUser('staff@waselna.local', $adminPassword, 'Demo Staff', 'restaurant_staff', $bella->id, $branchId);
        $this->ensureUser('courier@waselna.local', $adminPassword, 'Demo Courier', 'courier');
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $items
     */
    private function ensureRestaurant(string $name, string $description, string $cuisine, array $items): Restaurant
    {
        $existing = Restaurant::query()->where('name', $name)->first();

        if ($existing !== null) {
            return $existing;
        }

        $restaurant = Restaurant::query()->create([
            'id' => (string) Str::ulid(),
            'name' => $name,
            'description' => $description,
            'status' => 'published',
            'currency' => 'USD',
            'tax_rate_bp' => 800,
            'base_delivery_fee' => 299,
            'rating' => 4.5,
        ]);

        $restaurant->cuisineTags()->create(['id' => (string) Str::ulid(), 'tag' => $cuisine]);

        RestaurantBranch::query()->create([
            'id' => (string) Str::ulid(),
            'restaurant_id' => $restaurant->id,
            'name' => $name.' — Main Branch',
            'address' => '123 Demo Street, Riyadh',
            'latitude' => 24.7136,
            'longitude' => 46.6753,
            'is_active' => true,
        ]);

        $category = MenuCategory::query()->create([
            'id' => (string) Str::ulid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Popular',
            'display_order' => 0,
        ]);

        foreach ($items as $index => [$itemName, $itemDescription, $price]) {
            MenuItem::query()->create([
                'id' => (string) Str::ulid(),
                'restaurant_id' => $restaurant->id,
                'category_id' => $category->id,
                'name' => $itemName,
                'description' => $itemDescription,
                'price_minor' => $price,
                'is_available' => true,
                'display_order' => $index,
            ]);
        }

        return $restaurant;
    }

    private function ensureUser(
        string $email,
        string $password,
        string $displayName,
        string $role,
        ?string $restaurantId = null,
        ?string $branchId = null,
    ): void {
        $emailLower = mb_strtolower(trim($email));

        if (User::query()->where('email_lower', $emailLower)->exists()) {
            return;
        }

        User::query()->create([
            'id' => (string) Str::ulid(),
            'email' => $email,
            'email_lower' => $emailLower,
            'password_hash' => Hash::make($password),
            'display_name' => $displayName,
            'role' => $role,
            'account_status' => 'active',
            'restaurant_id' => $restaurantId,
            'branch_id' => $branchId,
        ]);
    }
}
