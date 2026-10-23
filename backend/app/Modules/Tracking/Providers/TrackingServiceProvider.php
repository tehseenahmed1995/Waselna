<?php

declare(strict_types=1);

namespace App\Modules\Tracking\Providers;

use App\Modules\Tracking\Http\Controllers\BroadcastAuthController;
use App\Modules\Tracking\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the container bindings owned by the Tracking module (Courier_Location ingestion and Order tracking read models).
 */
final class TrackingServiceProvider extends ServiceProvider
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
        Route::middleware(['api', 'auth.bearer:required'])->prefix('api/v1')->group(function (): void {
            // Courier reports its current position.
            Route::post('tracking/location', [TrackingController::class, 'report']);
            // Live tracking for an Order (customer / restaurant / admin).
            Route::get('orders/{order}/tracking', [TrackingController::class, 'order']);
            // Bearer-authenticated broadcasting auth for private channels.
            Route::post('broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);
        });
    }
}
