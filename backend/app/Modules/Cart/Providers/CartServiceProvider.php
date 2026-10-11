<?php

declare(strict_types=1);

namespace App\Modules\Cart\Providers;

use App\Modules\Cart\Http\Controllers\CartController;
use App\Modules\Cart\Services\CartService;
use App\Shared\Support\Clock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the container bindings owned by the Cart module (Cart state held in Redis).
 */
final class CartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CartService::class, fn ($app): CartService => new CartService(
            $app->make(Cache::class),
            $app->make(Clock::class),
        ));
    }

    public function boot(): void
    {
        Route::middleware(['api', 'auth.bearer:required'])->prefix('api/v1')->group(function (): void {
            Route::get('cart', [CartController::class, 'show']);
            Route::post('cart/items', [CartController::class, 'add']);
            Route::patch('cart/lines/{line}', [CartController::class, 'updateLine']);
            Route::delete('cart/lines/{line}', [CartController::class, 'removeLine']);
        });
    }
}
