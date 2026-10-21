<?php

declare(strict_types=1);

namespace App\Modules\Review\Providers;

use App\Modules\Review\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the container bindings owned by the Review module (Reviews and Aggregate_Rating).
 */
final class ReviewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(function (): void {
            // Public: read a Restaurant's reviews.
            Route::get('restaurants/{restaurant}/reviews', [ReviewController::class, 'forRestaurant']);
        });

        Route::middleware(['api', 'auth.bearer:required'])->prefix('api/v1')->group(function (): void {
            Route::post('orders/{order}/review', [ReviewController::class, 'submit']);
        });
    }
}
