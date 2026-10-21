<?php

declare(strict_types=1);

namespace App\Modules\Rider\Providers;

use App\Modules\Rider\Http\Controllers\RiderController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the container bindings owned by the Rider module (Courier profiles and Rider_Status).
 */
final class RiderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Route::middleware(['api', 'auth.bearer:required'])->prefix('api/v1')->group(function (): void {
            Route::get('rider/profile', [RiderController::class, 'profile']);
            Route::patch('rider/profile', [RiderController::class, 'updateProfile']);
            Route::post('rider/online', [RiderController::class, 'goOnline']);
            Route::post('rider/offline', [RiderController::class, 'goOffline']);
        });
    }
}
