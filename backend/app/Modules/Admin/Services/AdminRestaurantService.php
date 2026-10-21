<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Catalog\Models\Restaurant;
use App\Modules\Catalog\Models\RestaurantBranch;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Enums\Currency;
use App\Shared\Enums\RestaurantStatus;
use App\Shared\Support\Clock;
use App\Shared\Support\IdGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Administrator (super-admin) restaurant management surface (Requirement 37):
 * full CRUD plus one-call visibility control.
 *
 * Unlike the Restaurant_Operator lifecycle, the super-admin override does NOT
 * require publication prerequisites or a specific starting status: the platform
 * owner may publish or suspend any Restaurant directly. Suspending sets the
 * status to `suspended`, which the Customer discovery query (status = published)
 * excludes immediately, so a non-paying or misbehaving Restaurant disappears
 * from search at once.
 */
final class AdminRestaurantService
{
    public function __construct(
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
    ) {}

    /**
     * One Restaurant's detail for the admin edit screen.
     *
     * @return array<string, mixed>
     */
    public function get(string $restaurantId): array
    {
        return $this->present($this->findOrFail($restaurantId));
    }

    /**
     * Onboard a new Restaurant with one Restaurant_Branch. Created `published`
     * by default so it is immediately discoverable; pass published=false to
     * stage it hidden.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $now = $this->clock->nowUtc();
        $currency = $this->assertCurrency($input['currency'] ?? Currency::USD->value);
        $published = (bool) ($input['published'] ?? true);

        return DB::transaction(function () use ($input, $now, $published, $currency): array {
            /** @var Restaurant $restaurant */
            $restaurant = Restaurant::query()->create([
                'id' => $this->ids->ulid(),
                'name' => (string) $input['name'],
                'description' => $input['description'] ?? null,
                'status' => $published ? RestaurantStatus::Published->value : RestaurantStatus::Draft->value,
                'currency' => $currency,
                'tax_rate_bp' => (int) ($input['tax_rate_bp'] ?? 0),
                'base_delivery_fee' => (int) ($input['base_delivery_fee'] ?? 0),
                'rating' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (! empty($input['cuisine'])) {
                $restaurant->cuisineTags()->create([
                    'id' => $this->ids->ulid(),
                    'tag' => (string) $input['cuisine'],
                ]);
            }

            // Every Restaurant needs at least one active Restaurant_Branch to be
            // serviceable; create a main branch from the supplied address.
            RestaurantBranch::query()->create([
                'id' => $this->ids->ulid(),
                'restaurant_id' => $restaurant->id,
                'name' => ($input['name'] ?? 'Restaurant').' — Main Branch',
                'address' => (string) ($input['address'] ?? 'Address pending'),
                'latitude' => isset($input['latitude']) ? (float) $input['latitude'] : 24.7136,
                'longitude' => isset($input['longitude']) ? (float) $input['longitude'] : 46.6753,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->present($restaurant->fresh() ?? $restaurant);
        });
    }

    /**
     * Update a Restaurant's editable profile fields. Only the keys present in
     * $input are changed.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $restaurantId, array $input): array
    {
        $restaurant = $this->findOrFail($restaurantId);

        if (array_key_exists('name', $input)) {
            $restaurant->name = (string) $input['name'];
        }
        if (array_key_exists('description', $input)) {
            $restaurant->description = $input['description'] !== null ? (string) $input['description'] : null;
        }
        if (array_key_exists('currency', $input)) {
            $restaurant->currency = $this->assertCurrency($input['currency']);
        }
        if (array_key_exists('tax_rate_bp', $input)) {
            $restaurant->tax_rate_bp = (int) $input['tax_rate_bp'];
        }
        if (array_key_exists('base_delivery_fee', $input)) {
            $restaurant->base_delivery_fee = (int) $input['base_delivery_fee'];
        }

        $restaurant->updated_at = $this->clock->nowUtc();
        $restaurant->save();

        return $this->present($restaurant);
    }

    /**
     * Remove a Restaurant and its owned rows (branches, menu cascade via FKs).
     */
    public function delete(string $restaurantId): void
    {
        $restaurant = $this->findOrFail($restaurantId);
        $restaurant->delete();
    }

    /**
     * Hide a Restaurant from Customer search with one call (Requirement 59.8:
     * a suspended Restaurant is excluded from Customer-facing results).
     *
     * @return array<string, mixed>
     */
    public function suspend(string $restaurantId): array
    {
        return $this->setStatus($restaurantId, RestaurantStatus::Suspended);
    }

    /**
     * Restore a Restaurant to Customer search.
     *
     * @return array<string, mixed>
     */
    public function publish(string $restaurantId): array
    {
        return $this->setStatus($restaurantId, RestaurantStatus::Published);
    }

    /**
     * @return array<string, mixed>
     */
    private function setStatus(string $restaurantId, RestaurantStatus $status): array
    {
        $restaurant = $this->findOrFail($restaurantId);
        $restaurant->status = $status->value;
        $restaurant->updated_at = $this->clock->nowUtc();
        $restaurant->save();

        return $this->present($restaurant);
    }

    private function findOrFail(string $restaurantId): Restaurant
    {
        /** @var Restaurant|null $restaurant */
        $restaurant = Restaurant::query()->find($restaurantId);

        if ($restaurant === null) {
            throw AuthorizationException::notFound('restaurant_not_found', 'No Restaurant matches that identifier.');
        }

        return $restaurant;
    }

    /**
     * Rejects an unsupported currency code with validation_failed, returning the
     * validated uppercase code.
     */
    private function assertCurrency(mixed $code): string
    {
        $code = strtoupper((string) $code);

        if (! Currency::isSupported($code)) {
            throw ValidationException::forField('currency', 'in:'.implode(',', Currency::values()));
        }

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Restaurant $restaurant): array
    {
        $status = RestaurantStatus::tryFrom((string) $restaurant->status);

        return [
            'id' => $restaurant->id,
            'name' => $restaurant->name,
            'description' => $restaurant->description,
            'status' => $restaurant->status,
            'status_label' => $status?->label() ?? $restaurant->status,
            // Backend-derived: whether this Restaurant is live in Customer search.
            // The admin UI branches on this instead of comparing a status string.
            'is_discoverable' => $status?->isDiscoverable() ?? false,
            'currency' => $restaurant->currency,
            'tax_rate_bp' => (int) ($restaurant->tax_rate_bp ?? 0),
            'base_delivery_fee' => (int) ($restaurant->base_delivery_fee ?? 0),
            'rating' => $restaurant->rating,
        ];
    }
}
