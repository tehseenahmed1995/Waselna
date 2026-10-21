<?php

declare(strict_types=1);

namespace App\Modules\Order\Providers;

use App\Modules\Order\Domain\OrderStateMachine;
use App\Modules\Order\Http\Controllers\OrderController;
use App\Modules\Order\Http\Controllers\RestaurantOrderController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the container bindings owned by the Order module (Orders, Order_Lines, and the Order_State_Machine).
 */
final class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrderStateMachine::class);
    }

    public function boot(): void
    {
        Route::middleware(['api', 'auth.bearer:required'])->prefix('api/v1')->group(function (): void {
            Route::post('orders', [OrderController::class, 'store']);
            Route::get('orders', [OrderController::class, 'index']);
            Route::get('orders/{order}', [OrderController::class, 'show']);

            // Restaurant operator/staff order queue and handling (Requirement 12).
            Route::get('restaurant/orders', [RestaurantOrderController::class, 'queue']);
            Route::post('restaurant/orders/{order}/accept', [RestaurantOrderController::class, 'accept']);
            Route::post('restaurant/orders/{order}/reject', [RestaurantOrderController::class, 'reject']);
            Route::post('restaurant/orders/{order}/ready', [RestaurantOrderController::class, 'ready']);
        });
    }
}
