<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Restaurant;
use App\Shared\Domain\Exceptions\AuthorizationException;

/**
 * Customer-facing Restaurant discovery and Menu read models (Requirements 3, 4).
 *
 * Only a Restaurant whose status is `published` appears in Customer-facing
 * results (Requirement 3.1).
 */
final class RestaurantDiscoveryService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function search(): array
    {
        return Restaurant::query()
            ->where('status', 'published')
            ->with('cuisineTags')
            ->orderBy('name')
            ->get()
            ->map(fn (Restaurant $r): array => $this->summary($r))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function view(string $restaurantId): array
    {
        /** @var Restaurant|null $restaurant */
        $restaurant = Restaurant::query()
            ->where('status', 'published')
            ->with(['cuisineTags', 'categories.items'])
            ->find($restaurantId);

        if ($restaurant === null) {
            throw AuthorizationException::notFound('restaurant_not_found', 'No published Restaurant matches that identifier.');
        }

        return [
            ...$this->summary($restaurant),
            'menu' => $restaurant->categories->map(fn ($category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'items' => $category->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'price' => ['minor_units' => $item->price_minor, 'currency' => $restaurant->currency],
                    'is_available' => $item->is_available,
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Restaurant $restaurant): array
    {
        return [
            'id' => $restaurant->id,
            'name' => $restaurant->name,
            'description' => $restaurant->description,
            'currency' => $restaurant->currency,
            'rating' => $restaurant->rating,
            'cuisine_tags' => $restaurant->cuisineTags->pluck('tag')->all(),
        ];
    }
}
