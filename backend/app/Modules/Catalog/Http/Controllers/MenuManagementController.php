<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Contracts\Services\MenuManagementService;
use App\Modules\Catalog\Domain\MenuCategoryCommand;
use App\Modules\Catalog\Domain\MenuItemCommand;
use App\Modules\Catalog\Domain\ModifierGroupCommand;
use App\Modules\Catalog\Domain\ModifierOptionCommand;
use App\Modules\Catalog\Domain\ProductVariantCommand;
use App\Modules\Catalog\Http\Requests\AvailabilityRequest;
use App\Modules\Catalog\Http\Requests\MenuCategoryRequest;
use App\Modules\Catalog\Http\Requests\MenuItemRequest;
use App\Modules\Catalog\Http\Requests\ModifierGroupRequest;
use App\Modules\Catalog\Http\Requests\ModifierOptionRequest;
use App\Modules\Catalog\Http\Requests\ProductVariantRequest;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Restaurant Menu management endpoints (Requirements 11, 34). The Controller
 * holds no rules: it resolves the acting Platform user, decides which Restaurant
 * the request targets, hands the Service a command, and renders the result.
 *
 * A Restaurant_Operator manages only the one Restaurant it owns; an Administrator
 * manages any. That ownership scope is enforced here (the route Restaurant must
 * match an Operator's own), and the Role gate itself is applied by the
 * `AuthorizeRole:restaurant_operator,administrator` middleware on the routes. Every
 * documented rejection — the Price range, the selection-count rule, the
 * collection bounds, the non-disclosing 404 — is raised inside the Service and
 * rendered by the single exception pipeline, so no error body is composed here.
 */
final class MenuManagementController
{
    public function __construct(private readonly MenuManagementService $menu) {}

    // ---- Menu_Category -----------------------------------------------------

    public function indexCategories(Request $request, string $restaurant): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $data = array_map(
            static fn ($view): array => $view->toArray(),
            $this->menu->listCategories($restaurantId),
        );

        return $this->collection($data);
    }

    public function storeCategory(MenuCategoryRequest $request, string $restaurant): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $view = $this->menu->createCategory(
            MenuCategoryCommand::fromValidated($restaurantId, $request->validated()),
        );

        return new JsonResponse(['data' => $view->toArray()], 201);
    }

    public function updateCategory(MenuCategoryRequest $request, string $restaurant, string $category): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $view = $this->menu->updateCategory(
            MenuCategoryCommand::fromValidated($restaurantId, $request->validated(), $category),
        );

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    // ---- Menu_Item ---------------------------------------------------------

    public function indexItems(Request $request, string $restaurant, string $category): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $data = array_map(
            static fn ($view): array => $view->toArray(),
            $this->menu->listItems($restaurantId, $category),
        );

        return $this->collection($data);
    }

    public function storeItem(MenuItemRequest $request, string $restaurant): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $view = $this->menu->createItem(
            MenuItemCommand::fromValidated($restaurantId, $request->validated()),
        );

        return new JsonResponse(['data' => $view->toArray()], 201);
    }

    public function updateItem(MenuItemRequest $request, string $restaurant, string $item): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $view = $this->menu->updateItem(
            MenuItemCommand::fromValidated($restaurantId, $request->validated(), $item),
        );

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    public function destroyItem(Request $request, string $restaurant, string $item): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $this->menu->archiveItem($restaurantId, $item);

        return new JsonResponse(['data' => ['status' => 'ok']], 200);
    }

    // ---- Product_Variant ---------------------------------------------------

    public function indexVariants(Request $request, string $restaurant, string $item): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $data = array_map(
            static fn ($view): array => $view->toArray(),
            $this->menu->listVariants($restaurantId, $item),
        );

        return $this->collection($data);
    }

    public function storeVariant(ProductVariantRequest $request, string $restaurant, string $item): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $view = $this->menu->createVariant(
            ProductVariantCommand::fromValidated($restaurantId, $item, $request->validated()),
        );

        return new JsonResponse(['data' => $view->toArray()], 201);
    }

    public function updateVariant(ProductVariantRequest $request, string $restaurant, string $item, string $variant): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $view = $this->menu->updateVariant(
            ProductVariantCommand::fromValidated($restaurantId, $item, $request->validated(), $variant),
        );

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    public function destroyVariant(Request $request, string $restaurant, string $item, string $variant): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $this->menu->deleteVariant($restaurantId, $item, $variant);

        return new JsonResponse(['data' => ['status' => 'ok']], 200);
    }

    // ---- Availability ------------------------------------------------------

    public function setItemAvailability(AvailabilityRequest $request, string $restaurant, string $item): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $view = $this->menu->setItemAvailability(
            $restaurantId,
            $item,
            (bool) $request->validated()['is_available'],
        );

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    public function setVariantAvailability(AvailabilityRequest $request, string $restaurant, string $item, string $variant): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $view = $this->menu->setVariantAvailability(
            $restaurantId,
            $item,
            $variant,
            (bool) $request->validated()['is_available'],
        );

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    // ---- Modifier_Group ----------------------------------------------------

    public function indexGroups(Request $request, string $restaurant, string $item): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $data = array_map(
            static fn ($view): array => $view->toArray(),
            $this->menu->listGroups($restaurantId, $item),
        );

        return $this->collection($data);
    }

    public function storeGroup(ModifierGroupRequest $request, string $restaurant, string $item): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $view = $this->menu->createGroup(
            ModifierGroupCommand::fromValidated($restaurantId, $item, $request->validated()),
        );

        return new JsonResponse(['data' => $view->toArray()], 201);
    }

    public function updateGroup(ModifierGroupRequest $request, string $restaurant, string $item, string $group): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $view = $this->menu->updateGroup(
            ModifierGroupCommand::fromValidated($restaurantId, $item, $request->validated(), $group),
        );

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    public function destroyGroup(Request $request, string $restaurant, string $item, string $group): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $this->menu->deleteGroup($restaurantId, $item, $group);

        return new JsonResponse(['data' => ['status' => 'ok']], 200);
    }

    // ---- Modifier_Option ---------------------------------------------------

    public function indexOptions(Request $request, string $restaurant, string $group): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $data = array_map(
            static fn ($view): array => $view->toArray(),
            $this->menu->listOptions($restaurantId, $group),
        );

        return $this->collection($data);
    }

    public function storeOption(ModifierOptionRequest $request, string $restaurant, string $group): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $view = $this->menu->createOption(
            ModifierOptionCommand::fromValidated($restaurantId, $group, $request->validated()),
        );

        return new JsonResponse(['data' => $view->toArray()], 201);
    }

    public function updateOption(ModifierOptionRequest $request, string $restaurant, string $group, string $option): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $view = $this->menu->updateOption(
            ModifierOptionCommand::fromValidated($restaurantId, $group, $request->validated(), $option),
        );

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    public function destroyOption(Request $request, string $restaurant, string $group, string $option): JsonResponse
    {
        $restaurantId = $this->targetRestaurant($request, $restaurant);

        $this->menu->deleteOption($restaurantId, $group, $option);

        return new JsonResponse(['data' => ['status' => 'ok']], 200);
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * The Restaurant the request targets.
     *
     * An Administrator manages any Restaurant, so the route value is used
     * directly. A Restaurant_Operator manages only the one Restaurant it owns:
     * a route value that does not match the Operator's own Restaurant is
     * `403 forbidden`, as Requirement 2.3's ownership check requires.
     */
    private function targetRestaurant(Request $request, string $routeRestaurantId): string
    {
        $actor = $this->actor($request);

        if ($actor->hasRole('administrator')) {
            return $routeRestaurantId;
        }

        if ($actor->restaurantId === null || $actor->restaurantId !== $routeRestaurantId) {
            throw AuthorizationException::forbidden(['restaurant_id' => $routeRestaurantId]);
        }

        return $routeRestaurantId;
    }

    private function actor(Request $request): ActorContext
    {
        $actor = $request->attributes->get('actor');

        if (! $actor instanceof ActorContext) {
            throw AuthorizationException::unauthenticated();
        }

        return $actor;
    }

    /**
     * @param  list<array<string, mixed>>  $data
     */
    private function collection(array $data): JsonResponse
    {
        return new JsonResponse(['data' => $data, 'total' => count($data), 'next_cursor' => null], 200);
    }
}
