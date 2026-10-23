<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Providers;

use App\Modules\Dispatch\Http\Controllers\DispatchController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the container bindings owned by the Dispatch module (Delivery_Jobs and Courier offers).
 */
final class DispatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Route::middleware(['api', 'auth.bearer:required'])->prefix('api/v1')->group(function (): void {
            Route::get('dispatch/available', [DispatchController::class, 'available']);
            Route::get('dispatch/active', [DispatchController::class, 'active']);
            Route::get('dispatch/history', [DispatchController::class, 'history']);
            Route::post('dispatch/jobs/{job}/accept', [DispatchController::class, 'accept']);
            Route::post('dispatch/jobs/{job}/pickup', [DispatchController::class, 'pickup']);
            Route::post('dispatch/jobs/{job}/transit', [DispatchController::class, 'transit']);
            Route::post('dispatch/jobs/{job}/deliver', [DispatchController::class, 'deliver']);
        });
    }
}
