<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Shared\Http\Middleware\AuthenticateBearer;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the container bindings owned by the Auth module (credentials, opaque tokens, and authorization decisions).
 */
final class AuthServiceProvider extends ServiceProvider
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
            Route::post('auth/register', [AuthController::class, 'register']);
            Route::post('auth/login', [AuthController::class, 'login']);
            Route::post('auth/refresh', [AuthController::class, 'refresh']);
            Route::post('auth/logout', [AuthController::class, 'logout'])
                ->middleware(AuthenticateBearer::class.':required');
            Route::get('auth/me', [AuthController::class, 'me'])
                ->middleware(AuthenticateBearer::class.':required');
        });
    }
}
