<?php

declare(strict_types=1);

namespace App\Modules\Notification\Providers;

use App\Modules\Notification\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the container bindings owned by the Notification module.
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Route::middleware(['api', 'auth.bearer:required'])->prefix('api/v1')->group(function (): void {
            Route::get('notifications', [NotificationController::class, 'index']);
            Route::post('notifications/read', [NotificationController::class, 'markRead']);
        });
    }
}
