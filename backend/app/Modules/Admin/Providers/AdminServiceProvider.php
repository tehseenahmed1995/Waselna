<?php

declare(strict_types=1);

namespace App\Modules\Admin\Providers;

use App\Modules\Admin\Boundary\PlatformConfigurationReader;
use App\Modules\Admin\Contracts\Services\PlatformConfigurationService;
use App\Modules\Admin\Http\Controllers\AdminController;
use App\Modules\Admin\Http\Controllers\PlatformConfigurationController;
use App\Modules\Admin\Services\PlatformConfigurationServiceImplementation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the container bindings owned by the Admin module (Administrator read
 * surfaces, Platform_Configuration, and audit).
 */
final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // PlatformConfigurationServiceImplementation serves both the writable
        // PlatformConfigurationService Service_Interface (the Administrator
        // surface) and the read-only PlatformConfigurationReader Module_Boundary
        // that Pricing, Dispatch, Account, and Restaurant order handling consume
        // (Requirements 37.9, 37.10). Bind the concrete once as a singleton and
        // alias both seams to it so every reader and the writer share one
        // instance and therefore one view of the stored row.
        $this->app->singleton(PlatformConfigurationServiceImplementation::class);
        $this->app->alias(PlatformConfigurationServiceImplementation::class, PlatformConfigurationService::class);
        $this->app->alias(PlatformConfigurationServiceImplementation::class, PlatformConfigurationReader::class);
    }

    public function boot(): void
    {
        Route::middleware(['api', 'auth.bearer:required', 'role:administrator'])
            ->prefix('api/v1/admin')
            ->group(function (): void {
                Route::get('summary', [AdminController::class, 'summary']);
                Route::get('orders', [AdminController::class, 'orders']);
                Route::get('restaurants', [AdminController::class, 'restaurants']);
                Route::get('couriers', [AdminController::class, 'couriers']);
                Route::get('deliveries', [AdminController::class, 'deliveries']);

                // Super-admin restaurant management (Requirement 37): onboard a
                // Restaurant and force its visibility with one call.
                Route::post('restaurants', [AdminController::class, 'createRestaurant']);
                Route::get('restaurants/{restaurant}', [AdminController::class, 'showRestaurant']);
                Route::patch('restaurants/{restaurant}', [AdminController::class, 'updateRestaurant']);
                Route::delete('restaurants/{restaurant}', [AdminController::class, 'deleteRestaurant']);
                Route::post('restaurants/{restaurant}/suspend', [AdminController::class, 'suspendRestaurant']);
                Route::post('restaurants/{restaurant}/publish', [AdminController::class, 'publishRestaurant']);

                // Platform_Configuration read and write (Requirements 37.9, 37.10).
                Route::get('configuration', [PlatformConfigurationController::class, 'show']);
                Route::put('configuration', [PlatformConfigurationController::class, 'update']);
            });
    }
}
