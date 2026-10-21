<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Repositories;

use App\Modules\Catalog\Models\MenuCategory;
use App\Modules\Catalog\Models\MenuItem;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\ModifierOption;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\Restaurant;
use App\Modules\Catalog\Services\MenuManagementServiceImplementation;

/**
 * The one place that queries the Menu tables for the management use cases
 * (Requirements 11, 34).
 *
 * Concrete and Eloquent-backed, matching the Account module's repository
 * deviation: the {@see MenuManagementServiceImplementation}
 * holds the rules, this holds the queries. Every lookup is scoped to the owning
 * Restaurant so a query can never return another Restaurant's Menu record, which
 * is what lets the service raise the non-disclosing 404 by owner scope alone.
 *
 * The `*ForUpdate` reads lock the parent row so the collection-limit counts of
 * Requirements 11.10 and 34.1 are race-free without a trigger (design "Collection
 * limits" note).
 */
final class MenuRepository
{
    /**
     * The owning Restaurant locked for the surrounding transaction, or null where
     * the identifier matches no Restaurant. Locking the parent serialises
     * concurrent creates so a collection count cannot be raced past its bound.
     */
    public function findRestaurantForUpdate(string $restaurantId): ?Restaurant
    {
        /** @var Restaurant|null $restaurant */
        $restaurant = Restaurant::query()
            ->where('id', $restaurantId)
            ->lockForUpdate()
            ->first();

        return $restaurant;
    }

    // ---- Menu_Category -----------------------------------------------------

    public function findCategoryOwned(string $restaurantId, string $categoryId): ?MenuCategory
    {
        /** @var MenuCategory|null $category */
        $category = MenuCategory::query()
            ->where('restaurant_id', $restaurantId)
            ->where('id', $categoryId)
            ->whereNull('archived_at')
            ->first();

        return $category;
    }

    public function countCategories(string $restaurantId): int
    {
        return MenuCategory::query()
            ->where('restaurant_id', $restaurantId)
            ->whereNull('archived_at')
            ->count();
    }

    /**
     * @return list<MenuCategory>
     */
    public function listCategories(string $restaurantId): array
    {
        return array_values(
            MenuCategory::query()
                ->where('restaurant_id', $restaurantId)
                ->whereNull('archived_at')
                ->orderBy('display_order')
                ->orderBy('name')
                ->get()
                ->all(),
        );
    }

    public function saveCategory(MenuCategory $category): void
    {
        $category->save();
    }

    // ---- Menu_Item ---------------------------------------------------------

    public function findItemOwned(string $restaurantId, string $itemId): ?MenuItem
    {
        /** @var MenuItem|null $item */
        $item = MenuItem::query()
            ->where('restaurant_id', $restaurantId)
            ->where('id', $itemId)
            ->whereNull('archived_at')
            ->first();

        return $item;
    }

    public function countItemsInCategory(string $categoryId): int
    {
        return MenuItem::query()
            ->where('category_id', $categoryId)
            ->whereNull('archived_at')
            ->count();
    }

    /**
     * @return list<MenuItem>
     */
    public function listItemsInCategory(string $restaurantId, string $categoryId): array
    {
        return array_values(
            MenuItem::query()
                ->where('restaurant_id', $restaurantId)
                ->where('category_id', $categoryId)
                ->whereNull('archived_at')
                ->orderBy('display_order')
                ->orderBy('name')
                ->get()
                ->all(),
        );
    }

    public function saveItem(MenuItem $item): void
    {
        $item->save();
    }

    // ---- Product_Variant ---------------------------------------------------

    /**
     * A Product_Variant and its owning Menu_Item, both of the Restaurant, or null
     * where either does not belong to the Restaurant.
     */
    public function findVariantOwned(string $restaurantId, string $menuItemId, string $variantId): ?ProductVariant
    {
        /** @var ProductVariant|null $variant */
        $variant = ProductVariant::query()
            ->where('id', $variantId)
            ->where('menu_item_id', $menuItemId)
            ->whereExists(function ($query) use ($restaurantId, $menuItemId): void {
                $query->from('menu_items')
                    ->whereColumn('menu_items.id', 'product_variants.menu_item_id')
                    ->where('menu_items.id', $menuItemId)
                    ->where('menu_items.restaurant_id', $restaurantId)
                    ->whereNull('menu_items.archived_at');
            })
            ->first();

        return $variant;
    }

    public function countVariants(string $menuItemId): int
    {
        return ProductVariant::query()
            ->where('menu_item_id', $menuItemId)
            ->count();
    }

    /**
     * @return list<ProductVariant>
     */
    public function listVariants(string $menuItemId): array
    {
        return array_values(
            ProductVariant::query()
                ->where('menu_item_id', $menuItemId)
                ->orderBy('display_order')
                ->orderBy('name')
                ->get()
                ->all(),
        );
    }

    public function saveVariant(ProductVariant $variant): void
    {
        $variant->save();
    }

    public function deleteVariant(ProductVariant $variant): void
    {
        $variant->delete();
    }

    // ---- Modifier_Group ----------------------------------------------------

    public function findGroupOwned(string $restaurantId, string $menuItemId, string $groupId): ?ModifierGroup
    {
        /** @var ModifierGroup|null $group */
        $group = ModifierGroup::query()
            ->where('id', $groupId)
            ->where('menu_item_id', $menuItemId)
            ->whereExists(function ($query) use ($restaurantId, $menuItemId): void {
                $query->from('menu_items')
                    ->whereColumn('menu_items.id', 'modifier_groups.menu_item_id')
                    ->where('menu_items.id', $menuItemId)
                    ->where('menu_items.restaurant_id', $restaurantId)
                    ->whereNull('menu_items.archived_at');
            })
            ->first();

        return $group;
    }

    public function countGroups(string $menuItemId): int
    {
        return ModifierGroup::query()
            ->where('menu_item_id', $menuItemId)
            ->count();
    }

    /**
     * @return list<ModifierGroup>
     */
    public function listGroups(string $menuItemId): array
    {
        return array_values(
            ModifierGroup::query()
                ->where('menu_item_id', $menuItemId)
                ->orderBy('display_order')
                ->orderBy('name')
                ->get()
                ->all(),
        );
    }

    public function saveGroup(ModifierGroup $group): void
    {
        $group->save();
    }

    public function deleteGroup(ModifierGroup $group): void
    {
        $group->delete();
    }

    // ---- Modifier_Option ---------------------------------------------------

    public function findOptionOwned(string $restaurantId, string $groupId, string $optionId): ?ModifierOption
    {
        /** @var ModifierOption|null $option */
        $option = ModifierOption::query()
            ->where('id', $optionId)
            ->where('modifier_group_id', $groupId)
            ->whereExists(function ($query) use ($restaurantId, $groupId): void {
                $query->from('modifier_groups')
                    ->join('menu_items', 'menu_items.id', '=', 'modifier_groups.menu_item_id')
                    ->whereColumn('modifier_groups.id', 'modifier_options.modifier_group_id')
                    ->where('modifier_groups.id', $groupId)
                    ->where('menu_items.restaurant_id', $restaurantId)
                    ->whereNull('menu_items.archived_at');
            })
            ->first();

        return $option;
    }

    /**
     * The owning Modifier_Group of the Restaurant, or null where the group does
     * not belong to the Restaurant. Used to validate the parent before an option
     * create.
     */
    public function findGroupOfRestaurant(string $restaurantId, string $groupId): ?ModifierGroup
    {
        /** @var ModifierGroup|null $group */
        $group = ModifierGroup::query()
            ->where('modifier_groups.id', $groupId)
            ->whereExists(function ($query) use ($restaurantId): void {
                $query->from('menu_items')
                    ->whereColumn('menu_items.id', 'modifier_groups.menu_item_id')
                    ->where('menu_items.restaurant_id', $restaurantId)
                    ->whereNull('menu_items.archived_at');
            })
            ->first();

        return $group;
    }

    public function countOptions(string $groupId): int
    {
        return ModifierOption::query()
            ->where('modifier_group_id', $groupId)
            ->count();
    }

    /**
     * @return list<ModifierOption>
     */
    public function listOptions(string $groupId): array
    {
        return array_values(
            ModifierOption::query()
                ->where('modifier_group_id', $groupId)
                ->orderBy('display_order')
                ->orderBy('name')
                ->get()
                ->all(),
        );
    }

    public function saveOption(ModifierOption $option): void
    {
        $option->save();
    }

    public function deleteOption(ModifierOption $option): void
    {
        $option->delete();
    }
}
