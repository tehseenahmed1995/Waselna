<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Modules\Account\Boundary\AccountProvisioning;
use App\Modules\Auth\Boundary\CredentialDirectory;
use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\StaffController;
use App\Modules\Auth\Services\AuthenticationService;
use App\Modules\Catalog\Boundary\RestaurantProvisioning;
use App\Modules\Notification\Services\NotificationService;
use App\Shared\Http\Middleware\AuthenticateBearer;
use App\Shared\Http\Middleware\AuthorizeRole;
use App\Shared\Support\Clock;
use App\Shared\Support\IdGenerator;
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
        // AuthenticationService needs the Account provisioning boundary only
        // inside register(); injecting it eagerly would form a container cycle
        // (AuthenticationService is CredentialDirectory, which
        // ProfileServiceImplementation (the AccountProvisioning binding)
        // depends on). Construct it as a shared instance with the provisioning
        // edge deferred behind a Closure so neither constructor resolves the
        // other.
        $this->app->singleton(AuthenticationService::class, function ($app): AuthenticationService {
            return new AuthenticationService(
                $app->make(Clock::class),
                $app->make(IdGenerator::class),
                static fn (): AccountProvisioning => $app->make(AccountProvisioning::class),
                static fn (): RestaurantProvisioning => $app->make(RestaurantProvisioning::class),
                $app->make(NotificationService::class),
            );
        });

        // The Account module's password-change flow reaches the password hash
        // and Refresh_Tokens through this boundary (Requirements 31.5, 31.6).
        $this->app->bind(CredentialDirectory::class, AuthenticationService::class);
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

            // Restaurant_Staff management by a Restaurant_Operator (Requirement 1,
            // 20). Scoped to the acting operator's own Restaurant inside the Service.
            $operator = [AuthenticateBearer::class.':required', AuthorizeRole::class.':restaurant_operator'];
            Route::get('restaurant/staff', [StaffController::class, 'index'])->middleware($operator);
            Route::post('restaurant/staff', [StaffController::class, 'store'])->middleware($operator);
            Route::delete('restaurant/staff/{staff}', [StaffController::class, 'destroy'])->middleware($operator);
        });
    }
}
