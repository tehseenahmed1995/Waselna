<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Boundary\RestaurantStatusDirectory;
use App\Modules\Catalog\Contracts\Services\MenuManagementService;
use App\Modules\Catalog\Contracts\Services\MenuQueryService;
use App\Modules\Catalog\Contracts\Services\RestaurantLifecycleService;
use App\Modules\Catalog\Contracts\Services\RestaurantManagementService;
use App\Modules\Catalog\Http\Controllers\MenuManagementController;
use App\Modules\Catalog\Http\Controllers\RestaurantController;
use App\Modules\Catalog\Http\Controllers\RestaurantLifecycleController;
use App\Modules\Catalog\Http\Controllers\RestaurantManagementController;
use App\Modules\Catalog\Repositories\MenuCacheInvalidator;
use App\Modules\Catalog\Services\MenuManagementServiceImplementation;
use App\Modules\Catalog\Services\MenuQueryServiceImplementation;
use App\Modules\Catalog\Services\RestaurantLifecycleServiceImplementation;
use App\Modules\Catalog\Services\RestaurantManagementServiceImplementation;
use App\Modules\Catalog\Services\RestaurantStatusDirectoryImplementation;
use App\Shared\Http\Middleware\AuthenticateBearer;
use App\Shared\Http\Middleware\AuthorizeRole;
use App\Shared\Support\CacheOrFallback;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Registers the container bindings owned by the Catalog module (Restaurants, Branches, Menus, and Menu_Items).
 */
final class CatalogServiceProvider extends ServiceProvider
{
    /**
     * Service_Interface to Service_Implementation bindings owned by this module.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        MenuManagementService::class => MenuManagementServiceImplementation::class,
        MenuQueryService::class => MenuQueryServiceImplementation::class,
        // Restaurant profile and Restaurant_Branch management (task 7.6;
        // Requirement 32). Ownership and the time-zone, 50-branch, and 20-zone
        // rules are decided inside the Service.
        RestaurantManagementService::class => RestaurantManagementServiceImplementation::class,
        // The Restaurant_Status lifecycle Service_Interface (Requirements 18.1,
        // 18.2, 59.3–59.9).
        RestaurantLifecycleService::class => RestaurantLifecycleServiceImplementation::class,
    ];

    /**
     * Module_Boundary interfaces and external-dependency adapters owned by this module.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        // The lifecycle-status seam the Order checkout path reads to reject a
        // suspended Restaurant with `restaurant_suspended` (Requirement 18.2).
        RestaurantStatusDirectory::class => RestaurantStatusDirectoryImplementation::class,
    ];

    /**
     * Registers bindings that need more than a class-string mapping.
     */
    public function register(): void
    {
        // The read-model invalidator wraps the default Cache_Store, matching the
        // Cart module's explicit resolution of the cache contract (Requirement
        // 34.7). Bound as a singleton so every availability write shares one
        // Cache_Store handle.
        $this->app->singleton(
            MenuCacheInvalidator::class,
            fn ($app): MenuCacheInvalidator => new MenuCacheInvalidator($app->make(Cache::class)),
        );

        // The Menu read model (task 8.3) is served through CacheOrFallback over
        // the same default Cache_Store the invalidator forgets, so a committed
        // availability write is reflected by the next read (Requirement 4.6). The
        // wrapper records a Cache_Store outage in the Observability_Stack through
        // the structured logger and still serves from PostgreSQL
        // (Requirement 21.4). Bound locally to the read-model service so the
        // search read model of task 9.4 can later compose its own wrapper over
        // the same shared class.
        $this->app->when(MenuQueryServiceImplementation::class)
            ->needs(CacheOrFallback::class)
            ->give(fn ($app): CacheOrFallback => new CacheOrFallback(
                $app->make(Cache::class),
                $app->make(LoggerInterface::class),
            ));
    }

    /**
     * Wires module routes, event subscriptions, and policies once every provider is registered.
     */
    public function boot(): void
    {
        $required = AuthenticateBearer::class.':required';
        // Only a Restaurant_Operator or an Administrator may manage a Menu
        // (Requirements 11, 34); ownership of the specific Restaurant is enforced
        // in the Controller and Service.
        $manage = AuthorizeRole::class.':restaurant_operator,administrator';

        Route::middleware('api')->prefix('api/v1')->group(function () use ($required, $manage): void {
            // Customer-facing discovery (task 7.1).
            Route::get('restaurants', [RestaurantController::class, 'index']);
            Route::get('restaurants/{restaurant}', [RestaurantController::class, 'show']);

            // Menu management (Requirements 11, 34).
            Route::middleware([$required, $manage])->group(function (): void {
                // Restaurant_Status lifecycle (Requirements 18.1, 18.2,
                // 59.3–59.9). The route Role gate admits a Restaurant_Operator
                // and an Administrator; the per-edge actor rule — which Role may
                // make which transition and, for an Operator, over its own
                // Restaurant — is decided inside the Service.
                Route::post('restaurants/{restaurant}/submit-for-review', [RestaurantLifecycleController::class, 'submitForReview']);
                Route::patch('restaurants/{restaurant}/status', [RestaurantLifecycleController::class, 'transition']);

                // Restaurant profile and Restaurant_Branch management (task 7.6;
                // Requirement 32). Ownership of the route Restaurant is decided
                // inside the Service, so a Restaurant another Operator owns reads
                // as the non-disclosing restaurant_not_found.
                Route::get('restaurants/{restaurant}/profile', [RestaurantManagementController::class, 'showProfile']);
                Route::patch('restaurants/{restaurant}/profile', [RestaurantManagementController::class, 'updateProfile']);

                Route::post('restaurants/{restaurant}/branches', [RestaurantManagementController::class, 'storeBranch']);
                Route::patch('restaurants/{restaurant}/branches/{branch}', [RestaurantManagementController::class, 'updateBranch']);
                Route::post('restaurants/{restaurant}/branches/{branch}/activate', [RestaurantManagementController::class, 'activateBranch']);
                Route::post('restaurants/{restaurant}/branches/{branch}/deactivate', [RestaurantManagementController::class, 'deactivateBranch']);
                Route::put('restaurants/{restaurant}/branches/{branch}/zones', [RestaurantManagementController::class, 'assignZones']);

                // Menu_Categories.
                Route::get('restaurants/{restaurant}/menu/categories', [MenuManagementController::class, 'indexCategories']);
                Route::post('restaurants/{restaurant}/menu/categories', [MenuManagementController::class, 'storeCategory']);
                Route::patch('restaurants/{restaurant}/menu/categories/{category}', [MenuManagementController::class, 'updateCategory']);

                // Menu_Items.
                Route::get('restaurants/{restaurant}/menu/categories/{category}/items', [MenuManagementController::class, 'indexItems']);
                Route::post('restaurants/{restaurant}/menu/items', [MenuManagementController::class, 'storeItem']);
                Route::patch('restaurants/{restaurant}/menu/items/{item}', [MenuManagementController::class, 'updateItem']);
                Route::delete('restaurants/{restaurant}/menu/items/{item}', [MenuManagementController::class, 'destroyItem']);

                // Product_Variants.
                Route::get('restaurants/{restaurant}/menu/items/{item}/variants', [MenuManagementController::class, 'indexVariants']);
                Route::post('restaurants/{restaurant}/menu/items/{item}/variants', [MenuManagementController::class, 'storeVariant']);
                Route::patch('restaurants/{restaurant}/menu/items/{item}/variants/{variant}', [MenuManagementController::class, 'updateVariant']);
                Route::delete('restaurants/{restaurant}/menu/items/{item}/variants/{variant}', [MenuManagementController::class, 'destroyVariant']);

                // Modifier_Groups.
                Route::get('restaurants/{restaurant}/menu/items/{item}/modifier-groups', [MenuManagementController::class, 'indexGroups']);
                Route::post('restaurants/{restaurant}/menu/items/{item}/modifier-groups', [MenuManagementController::class, 'storeGroup']);
                Route::patch('restaurants/{restaurant}/menu/items/{item}/modifier-groups/{group}', [MenuManagementController::class, 'updateGroup']);
                Route::delete('restaurants/{restaurant}/menu/items/{item}/modifier-groups/{group}', [MenuManagementController::class, 'destroyGroup']);

                // Modifier_Options.
                Route::get('restaurants/{restaurant}/menu/modifier-groups/{group}/options', [MenuManagementController::class, 'indexOptions']);
                Route::post('restaurants/{restaurant}/menu/modifier-groups/{group}/options', [MenuManagementController::class, 'storeOption']);
                Route::patch('restaurants/{restaurant}/menu/modifier-groups/{group}/options/{option}', [MenuManagementController::class, 'updateOption']);
                Route::delete('restaurants/{restaurant}/menu/modifier-groups/{group}/options/{option}', [MenuManagementController::class, 'destroyOption']);
            });

            // Availability toggling (Requirements 34.7, 34.8). A Restaurant_Staff
            // may set the availability flag of a Menu_Item or Product_Variant of
            // its Restaurant_Branch's Restaurant (Requirement 20.4) in addition
            // to a Restaurant_Operator or Administrator, so this gate widens the
            // Role set; Requirement 20.5 keeps every other Menu write off limits
            // to a Restaurant_Staff through the narrower gate above.
            $toggle = AuthorizeRole::class.':restaurant_operator,administrator,restaurant_staff';
            Route::middleware([$required, $toggle])->group(function (): void {
                Route::patch('restaurants/{restaurant}/menu/items/{item}/availability', [MenuManagementController::class, 'setItemAvailability']);
                Route::patch('restaurants/{restaurant}/menu/items/{item}/variants/{variant}/availability', [MenuManagementController::class, 'setVariantAvailability']);
            });
        });
    }
}
