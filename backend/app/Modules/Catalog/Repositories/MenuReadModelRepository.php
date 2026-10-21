<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Repositories;

use App\Modules\Catalog\Domain\MenuCategoryReadModel;
use App\Modules\Catalog\Domain\MenuItemReadModel;
use App\Modules\Catalog\Domain\MenuReadModel;
use App\Modules\Catalog\Domain\ModifierGroupReadModel;
use App\Modules\Catalog\Domain\ModifierOptionReadModel;
use App\Modules\Catalog\Models\MenuCategory;
use App\Modules\Catalog\Models\MenuItem;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\ModifierOption;
use App\Modules\Catalog\Models\Restaurant;
use App\Modules\Catalog\Services\MenuQueryServiceImplementation;

/**
 * The one place that composes the Customer-facing Menu read model from the
 * stored Menu tables (Requirement 4). It is the PostgreSQL source of truth that
 * {@see MenuQueryServiceImplementation} serves
 * through `CacheOrFallback`: the identical projection feeds the cache populate
 * and the cache-miss / cache-failure fallback, which is what makes a cached read
 * equal an uncached read (Requirement 4.5).
 *
 * Concrete and Eloquent-backed, matching the module's documented repository
 * deviation: the Service holds the rules, this holds the queries. Ordering is
 * done in the query at every level by ascending display-order then ascending
 * name (Requirement 4.1), so the composed value is deterministic without an
 * in-memory sort. Unavailable Menu_Items are included, not filtered — the
 * availability flag is projected, never used as a `WHERE` (Requirement 4.4).
 * Archived Menu_Categories and Menu_Items are excluded because an archived
 * record is no longer part of the live Menu (Requirement 11.6).
 */
final class MenuReadModelRepository
{
    /**
     * The composed Menu read model for the published Restaurant, or null where
     * the identifier matches no Restaurant whose Restaurant_Status is
     * `published` (Requirement 4.9). Returning null — rather than throwing — lets
     * the Service raise the single non-disclosing 404 and keeps this layer free
     * of HTTP concerns.
     */
    public function composeForPublished(string $restaurantId): ?MenuReadModel
    {
        /** @var Restaurant|null $restaurant */
        $restaurant = Restaurant::query()
            ->where('id', $restaurantId)
            ->where('status', 'published')
            ->first();

        if ($restaurant === null) {
            return null;
        }

        $categories = $this->loadCategories($restaurantId);

        return new MenuReadModel(
            restaurantId: $restaurant->id,
            currency: $restaurant->currency,
            categories: $categories,
        );
    }

    /**
     * @return list<MenuCategoryReadModel>
     */
    private function loadCategories(string $restaurantId): array
    {
        $categories = MenuCategory::query()
            ->where('restaurant_id', $restaurantId)
            ->whereNull('archived_at')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $out = [];
        foreach ($categories as $category) {
            $out[] = new MenuCategoryReadModel(
                id: $category->id,
                name: $category->name,
                displayOrder: $category->display_order,
                items: $this->loadItems($restaurantId, $category->id),
            );
        }

        return $out;
    }

    /**
     * @return list<MenuItemReadModel>
     */
    private function loadItems(string $restaurantId, string $categoryId): array
    {
        $items = MenuItem::query()
            ->where('restaurant_id', $restaurantId)
            ->where('category_id', $categoryId)
            ->whereNull('archived_at')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $out = [];
        foreach ($items as $item) {
            $out[] = new MenuItemReadModel(
                id: $item->id,
                name: $item->name,
                description: $item->description,
                priceMinor: $item->price_minor,
                isAvailable: $item->is_available,
                displayOrder: $item->display_order,
                modifierGroups: $this->loadGroups($item->id),
            );
        }

        return $out;
    }

    /**
     * @return list<ModifierGroupReadModel>
     */
    private function loadGroups(string $menuItemId): array
    {
        $groups = ModifierGroup::query()
            ->where('menu_item_id', $menuItemId)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $out = [];
        foreach ($groups as $group) {
            $out[] = new ModifierGroupReadModel(
                id: $group->id,
                name: $group->name,
                minSelections: $group->min_selections,
                maxSelections: $group->max_selections,
                displayOrder: $group->display_order,
                options: $this->loadOptions($group->id),
            );
        }

        return $out;
    }

    /**
     * @return list<ModifierOptionReadModel>
     */
    private function loadOptions(string $groupId): array
    {
        $options = ModifierOption::query()
            ->where('modifier_group_id', $groupId)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $out = [];
        foreach ($options as $option) {
            $out[] = new ModifierOptionReadModel(
                id: $option->id,
                name: $option->name,
                priceDeltaMinor: $option->price_delta_minor,
                displayOrder: $option->display_order,
            );
        }

        return $out;
    }
}
