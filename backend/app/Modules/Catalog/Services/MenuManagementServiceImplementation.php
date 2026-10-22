<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\Services\MenuManagementService;
use App\Modules\Catalog\Domain\MenuCategoryCommand;
use App\Modules\Catalog\Domain\MenuCategoryView;
use App\Modules\Catalog\Domain\MenuItemCommand;
use App\Modules\Catalog\Domain\MenuItemView;
use App\Modules\Catalog\Domain\ModifierGroupCommand;
use App\Modules\Catalog\Domain\ModifierGroupView;
use App\Modules\Catalog\Domain\ModifierOptionCommand;
use App\Modules\Catalog\Domain\ModifierOptionView;
use App\Modules\Catalog\Domain\ProductVariantCommand;
use App\Modules\Catalog\Domain\ProductVariantView;
use App\Modules\Catalog\Events\MenuItemAvailabilityChanged;
use App\Modules\Catalog\Models\MenuCategory;
use App\Modules\Catalog\Models\MenuItem;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\ModifierOption;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Repositories\MenuCacheInvalidator;
use App\Modules\Catalog\Repositories\MenuRepository;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\Clock;
use App\Shared\Support\IdGenerator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Menu management for Menu_Categories, Menu_Items, Product_Variants,
 * Modifier_Groups, and Modifier_Options (Requirements 11, 34).
 *
 * The service holds the rules; the {@see MenuRepository} holds the queries.
 * Price range (`invalid_price`, Requirement 11.3), selection-count ordering
 * (`invalid_modifier_group`, Requirement 11.4), and collection bounds
 * (`catalog_limit_reached`, Requirements 11.10, 34.1) are all enforced here, each
 * inside one database transaction that first locks the parent row so a count
 * check cannot be raced (design "Collection limits" note). Identifiers come only
 * from the injected {@see IdGenerator} and timestamps only from the injected
 * {@see Clock}, so creation order and archival instants are deterministic under
 * test. Ownership is enforced by scope: a lookup that does not match the acting
 * Restaurant returns the non-disclosing 404, never revealing another
 * Restaurant's Menu.
 */
final class MenuManagementServiceImplementation implements MenuManagementService
{
    /** The maximum Menu_Categories per Menu (Requirement 11.10). */
    public const MAX_CATEGORIES = 50;

    /** The maximum Menu_Items per Menu_Category (Requirement 11.10). */
    public const MAX_ITEMS_PER_CATEGORY = 200;

    /** The maximum Product_Variants per Menu_Item (Requirement 34.1). */
    public const MAX_VARIANTS_PER_ITEM = 20;

    /** The maximum Modifier_Groups per Menu_Item (Requirement 11.10). */
    public const MAX_GROUPS_PER_ITEM = 10;

    /** The maximum Modifier_Options per Modifier_Group (Requirement 11.10). */
    public const MAX_OPTIONS_PER_GROUP = 50;

    /** The lowest permitted Price in minor units (Requirements 11.3, 34.1). */
    public const MIN_PRICE_MINOR = 1;

    /** The highest permitted Price in minor units (Requirement 11.3). */
    public const MAX_PRICE_MINOR = 99_999_999;

    public function __construct(
        private readonly MenuRepository $menu,
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
        private readonly MenuCacheInvalidator $cache,
        private readonly Dispatcher $events,
    ) {}

    // ---- Menu_Category -----------------------------------------------------

    public function createCategory(MenuCategoryCommand $command): MenuCategoryView
    {
        return DB::transaction(function () use ($command): MenuCategoryView {
            $this->lockRestaurantOrFail($command->restaurantId);

            if ($this->menu->countCategories($command->restaurantId) >= self::MAX_CATEGORIES) {
                throw $this->limitReached('A Menu holds at most '.self::MAX_CATEGORIES.' Menu_Categories.');
            }

            $now = $this->clock->nowUtc();
            $category = new MenuCategory([
                'id' => $this->ids->ulid(),
                'restaurant_id' => $command->restaurantId,
                'name' => $command->name,
                'display_order' => $command->displayOrder,
                'archived_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->menu->saveCategory($category);

            return MenuCategoryView::fromModel($category);
        });
    }

    public function updateCategory(MenuCategoryCommand $command): MenuCategoryView
    {
        return DB::transaction(function () use ($command): MenuCategoryView {
            $categoryId = (string) $command->categoryId;
            $category = $this->menu->findCategoryOwned($command->restaurantId, $categoryId);

            if ($category === null) {
                throw $this->notFound($categoryId);
            }

            $category->fill([
                'name' => $command->name,
                'display_order' => $command->displayOrder,
                'updated_at' => $this->clock->nowUtc(),
            ]);
            $this->menu->saveCategory($category);

            return MenuCategoryView::fromModel($category);
        });
    }

    public function listCategories(string $restaurantId): array
    {
        return array_map(
            static fn (MenuCategory $c): MenuCategoryView => MenuCategoryView::fromModel($c),
            $this->menu->listCategories($restaurantId),
        );
    }

    // ---- Menu_Item ---------------------------------------------------------

    public function createItem(MenuItemCommand $command): MenuItemView
    {
        $this->assertPrice($command->priceMinor);

        return DB::transaction(function () use ($command): MenuItemView {
            $this->lockRestaurantOrFail($command->restaurantId);

            // The Menu_Category must belong to the Restaurant (Requirement 11.2).
            $category = $this->menu->findCategoryOwned($command->restaurantId, $command->categoryId);
            if ($category === null) {
                throw $this->notFound($command->categoryId);
            }

            if ($this->menu->countItemsInCategory($command->categoryId) >= self::MAX_ITEMS_PER_CATEGORY) {
                throw $this->limitReached('A Menu_Category holds at most '.self::MAX_ITEMS_PER_CATEGORY.' Menu_Items.');
            }

            $now = $this->clock->nowUtc();
            $item = new MenuItem([
                'id' => $this->ids->ulid(),
                'restaurant_id' => $command->restaurantId,
                'category_id' => $command->categoryId,
                'name' => $command->name,
                'description' => $command->description,
                'image_url' => $command->imageUrl,
                'image_url' => $command->imageUrl,
                'price_minor' => $command->priceMinor,
                'is_available' => $command->isAvailable,
                'display_order' => $command->displayOrder,
                'archived_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->menu->saveItem($item);

            return MenuItemView::fromModel($item);
        });
    }

    public function updateItem(MenuItemCommand $command): MenuItemView
    {
        $this->assertPrice($command->priceMinor);

        return DB::transaction(function () use ($command): MenuItemView {
            $itemId = (string) $command->itemId;
            $item = $this->menu->findItemOwned($command->restaurantId, $itemId);
            if ($item === null) {
                throw $this->notFound($itemId);
            }

            // A changed Menu_Category assignment must still belong to the
            // Restaurant (Requirement 11.2).
            if ($command->categoryId !== $item->category_id) {
                $category = $this->menu->findCategoryOwned($command->restaurantId, $command->categoryId);
                if ($category === null) {
                    throw $this->notFound($command->categoryId);
                }
            }

            $item->fill([
                'category_id' => $command->categoryId,
                'name' => $command->name,
                'description' => $command->description,
                'image_url' => $command->imageUrl,
                'image_url' => $command->imageUrl,
                'price_minor' => $command->priceMinor,
                'is_available' => $command->isAvailable,
                'display_order' => $command->displayOrder,
                'updated_at' => $this->clock->nowUtc(),
            ]);
            $this->menu->saveItem($item);

            return MenuItemView::fromModel($item);
        });
    }

    public function archiveItem(string $restaurantId, string $itemId): void
    {
        DB::transaction(function () use ($restaurantId, $itemId): void {
            $item = $this->menu->findItemOwned($restaurantId, $itemId);

            // An already-archived item matches no non-archived row, so the lookup
            // returns null and the non-disclosing 404 is raised; a repeat delete
            // therefore changes nothing (Requirement 11.6).
            if ($item === null) {
                throw $this->notFound($itemId);
            }

            $now = $this->clock->nowUtc();
            $item->fill(['archived_at' => $now, 'updated_at' => $now]);
            $this->menu->saveItem($item);
        });
    }

    public function listItems(string $restaurantId, string $categoryId): array
    {
        return array_map(
            static fn (MenuItem $i): MenuItemView => MenuItemView::fromModel($i),
            $this->menu->listItemsInCategory($restaurantId, $categoryId),
        );
    }

    // ---- Product_Variant ---------------------------------------------------

    public function createVariant(ProductVariantCommand $command): ProductVariantView
    {
        $this->assertPrice($command->priceMinor);

        return DB::transaction(function () use ($command): ProductVariantView {
            $this->lockRestaurantOrFail($command->restaurantId);

            $item = $this->menu->findItemOwned($command->restaurantId, $command->menuItemId);
            if ($item === null) {
                throw $this->notFound($command->menuItemId);
            }

            if ($this->menu->countVariants($command->menuItemId) >= self::MAX_VARIANTS_PER_ITEM) {
                throw $this->limitReached('A Menu_Item holds at most '.self::MAX_VARIANTS_PER_ITEM.' Product_Variants.');
            }

            $now = $this->clock->nowUtc();
            $variant = new ProductVariant([
                'id' => $this->ids->ulid(),
                'menu_item_id' => $command->menuItemId,
                'name' => $command->name,
                'price_minor' => $command->priceMinor,
                'is_available' => $command->isAvailable,
                'display_order' => $command->displayOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->menu->saveVariant($variant);

            return ProductVariantView::fromModel($variant);
        });
    }

    public function updateVariant(ProductVariantCommand $command): ProductVariantView
    {
        $this->assertPrice($command->priceMinor);

        return DB::transaction(function () use ($command): ProductVariantView {
            $variantId = (string) $command->variantId;
            $variant = $this->menu->findVariantOwned($command->restaurantId, $command->menuItemId, $variantId);
            if ($variant === null) {
                throw $this->notFound($variantId);
            }

            $variant->fill([
                'name' => $command->name,
                'price_minor' => $command->priceMinor,
                'is_available' => $command->isAvailable,
                'display_order' => $command->displayOrder,
                'updated_at' => $this->clock->nowUtc(),
            ]);
            $this->menu->saveVariant($variant);

            return ProductVariantView::fromModel($variant);
        });
    }

    public function deleteVariant(string $restaurantId, string $menuItemId, string $variantId): void
    {
        DB::transaction(function () use ($restaurantId, $menuItemId, $variantId): void {
            $variant = $this->menu->findVariantOwned($restaurantId, $menuItemId, $variantId);
            if ($variant === null) {
                throw $this->notFound($variantId);
            }
            $this->menu->deleteVariant($variant);
        });
    }

    public function listVariants(string $restaurantId, string $menuItemId): array
    {
        // Scope the parent to the Restaurant first so the list cannot leak
        // another Restaurant's variants.
        if ($this->menu->findItemOwned($restaurantId, $menuItemId) === null) {
            throw $this->notFound($menuItemId);
        }

        return array_map(
            static fn (ProductVariant $v): ProductVariantView => ProductVariantView::fromModel($v),
            $this->menu->listVariants($menuItemId),
        );
    }

    // ---- Availability ------------------------------------------------------

    public function setItemAvailability(string $restaurantId, string $itemId, bool $isAvailable): MenuItemView
    {
        return DB::transaction(function () use ($restaurantId, $itemId, $isAvailable): MenuItemView {
            $item = $this->menu->findItemOwned($restaurantId, $itemId);
            if ($item === null) {
                throw $this->notFound($itemId);
            }

            $changed = $item->is_available !== $isAvailable;

            // The submitted value is always persisted (Requirement 34.7); the
            // updated_at instant moves only when the flag actually changes so an
            // idempotent write leaves the row byte-identical.
            if ($changed) {
                $now = $this->clock->nowUtc();
                $item->fill(['is_available' => $isAvailable, 'updated_at' => $now]);
                $this->menu->saveItem($item);

                // The read-model invalidation and the domain event are deferred
                // to after the write commits, so a rolled-back transaction
                // publishes nothing and evicts nothing (design "post-commit
                // events"). Within the same restaurant, invalidation is one
                // Cache_Store round-trip, well inside the 5-second bound.
                $this->afterAvailabilityChange($restaurantId, $itemId, null, $isAvailable, $now);
            }

            return MenuItemView::fromModel($item);
        });
    }

    public function setVariantAvailability(string $restaurantId, string $menuItemId, string $variantId, bool $isAvailable): ProductVariantView
    {
        return DB::transaction(function () use ($restaurantId, $menuItemId, $variantId, $isAvailable): ProductVariantView {
            $variant = $this->menu->findVariantOwned($restaurantId, $menuItemId, $variantId);
            if ($variant === null) {
                throw $this->notFound($variantId);
            }

            $changed = $variant->is_available !== $isAvailable;

            if ($changed) {
                $now = $this->clock->nowUtc();
                $variant->fill(['is_available' => $isAvailable, 'updated_at' => $now]);
                $this->menu->saveVariant($variant);

                $this->afterAvailabilityChange($restaurantId, $menuItemId, $variantId, $isAvailable, $now);
            }

            return ProductVariantView::fromModel($variant);
        });
    }

    /**
     * Registers the after-commit reactions to a committed availability change:
     * the Catalog read-model invalidation (Requirement 34.7) always, and the
     * `menu_item.availability_changed` domain event (Requirements 34.8, 6.4)
     * only when the flag was set to false — the single trigger Requirement 34.8
     * names. The event carries the new flag so a subscriber need not re-read it.
     *
     * `DB::afterCommit` fires each callback once the outermost transaction
     * commits and never fires it on rollback, which is what makes a failed write
     * publish nothing and evict nothing. Each is published exactly once because
     * it is registered once per changed write.
     */
    private function afterAvailabilityChange(
        string $restaurantId,
        string $menuItemId,
        ?string $productVariantId,
        bool $isAvailable,
        \DateTimeImmutable $occurredAt,
    ): void {
        DB::afterCommit(function () use ($restaurantId): void {
            $this->cache->invalidateForRestaurant($restaurantId);
        });

        if ($isAvailable === false) {
            $event = new MenuItemAvailabilityChanged(
                restaurantId: $restaurantId,
                menuItemId: $menuItemId,
                productVariantId: $productVariantId,
                isAvailable: false,
                occurredAt: $occurredAt,
            );

            DB::afterCommit(function () use ($event): void {
                $this->events->dispatch($event);
            });
        }
    }

    // ---- Modifier_Group ----------------------------------------------------

    public function createGroup(ModifierGroupCommand $command): ModifierGroupView
    {
        $this->assertSelectionCounts($command->minSelections, $command->maxSelections);

        return DB::transaction(function () use ($command): ModifierGroupView {
            $this->lockRestaurantOrFail($command->restaurantId);

            $item = $this->menu->findItemOwned($command->restaurantId, $command->menuItemId);
            if ($item === null) {
                throw $this->notFound($command->menuItemId);
            }

            if ($this->menu->countGroups($command->menuItemId) >= self::MAX_GROUPS_PER_ITEM) {
                throw $this->limitReached('A Menu_Item holds at most '.self::MAX_GROUPS_PER_ITEM.' Modifier_Groups.');
            }

            $now = $this->clock->nowUtc();
            $group = new ModifierGroup([
                'id' => $this->ids->ulid(),
                'menu_item_id' => $command->menuItemId,
                'name' => $command->name,
                'min_selections' => $command->minSelections,
                'max_selections' => $command->maxSelections,
                'display_order' => $command->displayOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->menu->saveGroup($group);

            return ModifierGroupView::fromModel($group);
        });
    }

    public function updateGroup(ModifierGroupCommand $command): ModifierGroupView
    {
        $this->assertSelectionCounts($command->minSelections, $command->maxSelections);

        return DB::transaction(function () use ($command): ModifierGroupView {
            $groupId = (string) $command->groupId;
            $group = $this->menu->findGroupOwned($command->restaurantId, $command->menuItemId, $groupId);
            if ($group === null) {
                throw $this->notFound($groupId);
            }

            $group->fill([
                'name' => $command->name,
                'min_selections' => $command->minSelections,
                'max_selections' => $command->maxSelections,
                'display_order' => $command->displayOrder,
                'updated_at' => $this->clock->nowUtc(),
            ]);
            $this->menu->saveGroup($group);

            return ModifierGroupView::fromModel($group);
        });
    }

    public function deleteGroup(string $restaurantId, string $menuItemId, string $groupId): void
    {
        DB::transaction(function () use ($restaurantId, $menuItemId, $groupId): void {
            $group = $this->menu->findGroupOwned($restaurantId, $menuItemId, $groupId);
            if ($group === null) {
                throw $this->notFound($groupId);
            }
            $this->menu->deleteGroup($group);
        });
    }

    public function listGroups(string $restaurantId, string $menuItemId): array
    {
        if ($this->menu->findItemOwned($restaurantId, $menuItemId) === null) {
            throw $this->notFound($menuItemId);
        }

        return array_map(
            static fn (ModifierGroup $g): ModifierGroupView => ModifierGroupView::fromModel($g),
            $this->menu->listGroups($menuItemId),
        );
    }

    // ---- Modifier_Option ---------------------------------------------------

    public function createOption(ModifierOptionCommand $command): ModifierOptionView
    {
        return DB::transaction(function () use ($command): ModifierOptionView {
            $this->lockRestaurantOrFail($command->restaurantId);

            $group = $this->menu->findGroupOfRestaurant($command->restaurantId, $command->modifierGroupId);
            if ($group === null) {
                throw $this->notFound($command->modifierGroupId);
            }

            if ($this->menu->countOptions($command->modifierGroupId) >= self::MAX_OPTIONS_PER_GROUP) {
                throw $this->limitReached('A Modifier_Group holds at most '.self::MAX_OPTIONS_PER_GROUP.' Modifier_Options.');
            }

            $now = $this->clock->nowUtc();
            $option = new ModifierOption([
                'id' => $this->ids->ulid(),
                'modifier_group_id' => $command->modifierGroupId,
                'name' => $command->name,
                'price_delta_minor' => $command->priceDeltaMinor,
                'display_order' => $command->displayOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->menu->saveOption($option);

            return ModifierOptionView::fromModel($option);
        });
    }

    public function updateOption(ModifierOptionCommand $command): ModifierOptionView
    {
        return DB::transaction(function () use ($command): ModifierOptionView {
            $optionId = (string) $command->optionId;
            $option = $this->menu->findOptionOwned($command->restaurantId, $command->modifierGroupId, $optionId);
            if ($option === null) {
                throw $this->notFound($optionId);
            }

            $option->fill([
                'name' => $command->name,
                'price_delta_minor' => $command->priceDeltaMinor,
                'display_order' => $command->displayOrder,
                'updated_at' => $this->clock->nowUtc(),
            ]);
            $this->menu->saveOption($option);

            return ModifierOptionView::fromModel($option);
        });
    }

    public function deleteOption(string $restaurantId, string $groupId, string $optionId): void
    {
        DB::transaction(function () use ($restaurantId, $groupId, $optionId): void {
            $option = $this->menu->findOptionOwned($restaurantId, $groupId, $optionId);
            if ($option === null) {
                throw $this->notFound($optionId);
            }
            $this->menu->deleteOption($option);
        });
    }

    public function listOptions(string $restaurantId, string $groupId): array
    {
        if ($this->menu->findGroupOfRestaurant($restaurantId, $groupId) === null) {
            throw $this->notFound($groupId);
        }

        return array_map(
            static fn (ModifierOption $o): ModifierOptionView => ModifierOptionView::fromModel($o),
            $this->menu->listOptions($groupId),
        );
    }

    // ---- Shared rules ------------------------------------------------------

    /**
     * Locks the owning Restaurant for the transaction so a collection-limit count
     * is race-free, raising the non-disclosing 404 where it does not exist.
     */
    private function lockRestaurantOrFail(string $restaurantId): void
    {
        if ($this->menu->findRestaurantForUpdate($restaurantId) === null) {
            throw AuthorizationException::notFound(
                'restaurant_not_found',
                'No matching Restaurant was found.',
                ['restaurant_id' => $restaurantId],
            );
        }
    }

    /**
     * A Price outside [1, 99999999] minor units is rejected with `invalid_price`
     * and the stored record left unchanged (Requirement 11.3). Checked before the
     * transaction opens so nothing is written.
     */
    private function assertPrice(int $priceMinor): void
    {
        if ($priceMinor < self::MIN_PRICE_MINOR || $priceMinor > self::MAX_PRICE_MINOR) {
            throw ValidationException::because(
                'invalid_price',
                'A Price must be between '.self::MIN_PRICE_MINOR.' and '.self::MAX_PRICE_MINOR.' minor currency units.',
            );
        }
    }

    /**
     * A Modifier_Group whose minimum selection count exceeds its maximum is
     * rejected with `invalid_modifier_group` (Requirement 11.4). Checked before
     * the transaction opens so nothing is written.
     */
    private function assertSelectionCounts(int $min, int $max): void
    {
        if ($min > $max) {
            throw ValidationException::because(
                'invalid_modifier_group',
                'A Modifier_Group minimum selection count must not exceed its maximum.',
            );
        }
    }

    private function limitReached(string $message): ValidationException
    {
        return ValidationException::because('catalog_limit_reached', $message);
    }

    /**
     * The non-disclosing 404: identical whether the record does not exist or
     * belongs to another Restaurant, so the response never reveals another
     * Restaurant's Menu (design "Non-disclosure rules").
     */
    private function notFound(string $id): AuthorizationException
    {
        return AuthorizationException::notFound(
            'menu_item_not_found',
            'No matching Menu record was found.',
            ['id' => $id],
        );
    }
}
