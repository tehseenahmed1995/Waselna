<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts\Services;

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
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ValidationException;

/**
 * Menu management for a Restaurant_Operator or Administrator: Menu_Categories,
 * Menu_Items, Product_Variants, Modifier_Groups, and Modifier_Options
 * (Requirements 11, 34).
 *
 * One seam over one implementation, as Requirement 38 asks: the Controller and
 * tests depend on this interface. Price and selection-count validation that the
 * Form_Request cannot express — the Price range of Requirement 11.3 carrying
 * `invalid_price`, the selection-count rule of Requirement 11.4 carrying
 * `invalid_modifier_group` — is enforced here, and so are the collection bounds
 * of Requirements 11.10 and 34.1 carrying `catalog_limit_reached`, each checked
 * inside the write transaction against a locked parent row. Deletion archives a
 * Menu_Item rather than removing it (Requirement 11.6).
 */
interface MenuManagementService
{
    /**
     * Creates a Menu_Category for the Restaurant (Requirement 11.1).
     *
     * @throws ValidationException `catalog_limit_reached` when the Menu already
     *                             holds 50 Menu_Categories (Requirement 11.10); nothing is stored.
     * @throws AuthorizationException non-disclosing `restaurant_not_found` where
     *                                the Restaurant does not exist.
     */
    public function createCategory(MenuCategoryCommand $command): MenuCategoryView;

    /**
     * Updates a non-archived Menu_Category of the Restaurant (Requirement 11.1).
     *
     * @throws AuthorizationException non-disclosing `menu_item_not_found` where
     *                                the category is not the Restaurant's.
     */
    public function updateCategory(MenuCategoryCommand $command): MenuCategoryView;

    /**
     * @return list<MenuCategoryView>
     */
    public function listCategories(string $restaurantId): array;

    /**
     * Creates a Menu_Item in a Menu_Category of the Restaurant (Requirement 11.2).
     *
     * @throws ValidationException `invalid_price` outside [1, 99999999]
     *                             (Requirement 11.3) or `catalog_limit_reached` at 200 items
     *                             (Requirement 11.10); nothing is stored.
     * @throws AuthorizationException non-disclosing `menu_item_not_found` where
     *                                the Menu_Category is not the Restaurant's.
     */
    public function createItem(MenuItemCommand $command): MenuItemView;

    /**
     * Updates a non-archived Menu_Item of the Restaurant (Requirement 11.2).
     *
     * @throws ValidationException `invalid_price` outside [1, 99999999]
     *                             (Requirement 11.3); the stored Menu_Item is left unchanged.
     * @throws AuthorizationException non-disclosing `menu_item_not_found`.
     */
    public function updateItem(MenuItemCommand $command): MenuItemView;

    /**
     * Archives a Menu_Item, retaining the row for Order history
     * (Requirement 11.6). An already-archived item is the non-disclosing 404.
     *
     * @throws AuthorizationException non-disclosing `menu_item_not_found`.
     */
    public function archiveItem(string $restaurantId, string $itemId): void;

    /**
     * @return list<MenuItemView>
     */
    public function listItems(string $restaurantId, string $categoryId): array;

    /**
     * Creates a Product_Variant for a Menu_Item of the Restaurant
     * (Requirement 34.1).
     *
     * @throws ValidationException `invalid_price` below 1 (Requirements 11.3,
     *                             34.1) or `catalog_limit_reached` at 20 variants
     *                             (Requirement 34.1); nothing is stored.
     * @throws AuthorizationException non-disclosing `menu_item_not_found`.
     */
    public function createVariant(ProductVariantCommand $command): ProductVariantView;

    /**
     * Updates a Product_Variant of a Menu_Item of the Restaurant
     * (Requirement 34.1).
     *
     * @throws ValidationException `invalid_price` below 1; the stored variant is
     *                             left unchanged.
     * @throws AuthorizationException non-disclosing `menu_item_not_found`.
     */
    public function updateVariant(ProductVariantCommand $command): ProductVariantView;

    /**
     * @throws AuthorizationException non-disclosing `menu_item_not_found`.
     */
    public function deleteVariant(string $restaurantId, string $menuItemId, string $variantId): void;

    /**
     * Sets a Menu_Item's availability flag, persisting the submitted value
     * (Requirements 34.7, 11.5). On a change the cached Menu read model for the
     * Restaurant and the Restaurant's search pages are invalidated within 5
     * seconds of the commit; setting the flag to false additionally publishes
     * exactly one `menu_item.availability_changed` domain event carrying the
     * Menu_Item identifier, no Product_Variant identifier, the new flag, and the
     * UTC timestamp (Requirements 34.8, 6.4). Every existing Order is left
     * unchanged. An idempotent write of the already-stored value stores the same
     * value, invalidates nothing further, and publishes nothing.
     *
     * @throws AuthorizationException non-disclosing `menu_item_not_found`.
     */
    public function setItemAvailability(string $restaurantId, string $itemId, bool $isAvailable): MenuItemView;

    /**
     * Sets a Product_Variant's availability flag, persisting the submitted value
     * (Requirements 34.7, 11.5). On a change the cached Menu read model for the
     * Restaurant and the Restaurant's search pages are invalidated within 5
     * seconds of the commit; setting the flag to false additionally publishes
     * exactly one `menu_item.availability_changed` domain event carrying the
     * owning Menu_Item identifier, the Product_Variant identifier, the new flag,
     * and the UTC timestamp (Requirements 34.8, 6.4). Every existing Order is
     * left unchanged. An idempotent write of the already-stored value stores the
     * same value, invalidates nothing further, and publishes nothing.
     *
     * @throws AuthorizationException non-disclosing `menu_item_not_found`.
     */
    public function setVariantAvailability(string $restaurantId, string $menuItemId, string $variantId, bool $isAvailable): ProductVariantView;

    /**
     * @return list<ProductVariantView>
     */
    public function listVariants(string $restaurantId, string $menuItemId): array;

    /**
     * Creates a Modifier_Group for a Menu_Item of the Restaurant
     * (Requirements 11.4, 34.3).
     *
     * @throws ValidationException `invalid_modifier_group` where min > max
     *                             (Requirement 11.4) or `catalog_limit_reached` at 10 groups
     *                             (Requirement 11.10); nothing is stored.
     * @throws AuthorizationException non-disclosing `menu_item_not_found`.
     */
    public function createGroup(ModifierGroupCommand $command): ModifierGroupView;

    /**
     * @throws ValidationException `invalid_modifier_group` where min > max.
     * @throws AuthorizationException non-disclosing `menu_item_not_found`.
     */
    public function updateGroup(ModifierGroupCommand $command): ModifierGroupView;

    /**
     * @throws AuthorizationException non-disclosing `menu_item_not_found`.
     */
    public function deleteGroup(string $restaurantId, string $menuItemId, string $groupId): void;

    /**
     * @return list<ModifierGroupView>
     */
    public function listGroups(string $restaurantId, string $menuItemId): array;

    /**
     * Creates a Modifier_Option in a Modifier_Group of the Restaurant
     * (Requirement 11.2).
     *
     * @throws ValidationException `catalog_limit_reached` at 50 options
     *                             (Requirement 11.10); nothing is stored.
     * @throws AuthorizationException non-disclosing `menu_item_not_found`.
     */
    public function createOption(ModifierOptionCommand $command): ModifierOptionView;

    /**
     * @throws AuthorizationException non-disclosing `menu_item_not_found`.
     */
    public function updateOption(ModifierOptionCommand $command): ModifierOptionView;

    /**
     * @throws AuthorizationException non-disclosing `menu_item_not_found`.
     */
    public function deleteOption(string $restaurantId, string $groupId, string $optionId): void;

    /**
     * @return list<ModifierOptionView>
     */
    public function listOptions(string $restaurantId, string $groupId): array;
}
