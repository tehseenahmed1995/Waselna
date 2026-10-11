<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Http\Controllers\RestaurantController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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
    public array $bindings = [];

    /**
     * Module_Boundary interfaces and external-dependency adapters owned by this module.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];

    /**
     * Registers bindings that need more than a class-string mapping.
     */
    public function register(): void
    {
        //
    }

    /**
     * Wires module routes, event subscriptions, and policies once every provider is registered.
     */
    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(function (): void {
            Route::get('restaurants', [RestaurantController::class, 'index']);
            Route::get('restaurants/{restaurant}', [RestaurantController::class, 'show']);
        });
    }
}
