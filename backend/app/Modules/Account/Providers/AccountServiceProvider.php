<?php

declare(strict_types=1);

namespace App\Modules\Account\Providers;

use App\Modules\Account\Boundary\AccountProvisioning;
use App\Modules\Account\Boundary\AddressDirectory;
use App\Modules\Account\Boundary\NotificationPreferenceReader;
use App\Modules\Account\Contracts\Services\AddressService;
use App\Modules\Account\Contracts\Services\ProfileService;
use App\Modules\Account\Http\Controllers\AddressController;
use App\Modules\Account\Http\Controllers\ProfileController;
use App\Modules\Account\Services\AddressServiceImplementation;
use App\Modules\Account\Services\NotificationPreferenceReaderImplementation;
use App\Modules\Account\Services\ProfileServiceImplementation;
use App\Shared\Http\Middleware\AuthenticateBearer;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the container bindings owned by the Account module (customer profiles, Delivery_Addresses, and notification preferences).
 */
final class AccountServiceProvider extends ServiceProvider
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
    public array $singletons = [
        // The AddressService and the AddressDirectory boundary are the same
        // object: the Order/Pricing read is a second seam onto the one
        // implementation, so a single instance serves both (Requirement 31.7–31.11).
        NotificationPreferenceReader::class => NotificationPreferenceReaderImplementation::class,
    ];

    /**
     * Registers bindings that need more than a class-string mapping.
     */
    public function register(): void
    {
        // AddressServiceImplementation implements both the AddressService
        // Service_Interface and the AddressDirectory Module_Boundary. Bind the
        // concrete once as a singleton and alias both seams to it so a consumer
        // on either side shares the same instance.
        $this->app->singleton(AddressServiceImplementation::class);
        $this->app->alias(AddressServiceImplementation::class, AddressService::class);
        $this->app->alias(AddressServiceImplementation::class, AddressDirectory::class);

        // ProfileServiceImplementation serves both the ProfileService
        // Service_Interface (own-profile read and update, email- and
        // password-change) and the AccountProvisioning Module_Boundary the Auth
        // module calls during registration (Requirements 1.1, 16.12). Bind the
        // concrete once and alias both seams so the registration transaction and
        // the HTTP profile endpoints share one instance.
        $this->app->singleton(ProfileServiceImplementation::class);
        $this->app->alias(ProfileServiceImplementation::class, ProfileService::class);
        $this->app->alias(ProfileServiceImplementation::class, AccountProvisioning::class);
    }

    /**
     * Wires module routes, event subscriptions, and policies once every provider is registered.
     */
    public function boot(): void
    {
        $required = AuthenticateBearer::class.':required';

        Route::middleware('api')->prefix('api/v1')->group(function () use ($required): void {
            Route::get('account/profile', [ProfileController::class, 'show'])->middleware($required);
            Route::patch('account/profile', [ProfileController::class, 'update'])->middleware($required);
            Route::post('account/profile/email-change', [ProfileController::class, 'requestEmailChange'])->middleware($required);
            Route::post('account/profile/email-change/confirm', [ProfileController::class, 'confirmEmailChange'])->middleware($required);
            Route::post('account/profile/password', [ProfileController::class, 'changePassword'])->middleware($required);

            // Saved Delivery_Address endpoints (Requirements 31.7–31.11).
            Route::get('account/addresses', [AddressController::class, 'index'])->middleware($required);
            Route::post('account/addresses', [AddressController::class, 'store'])->middleware($required);
            Route::patch('account/addresses/{address}', [AddressController::class, 'update'])->middleware($required);
            Route::post('account/addresses/{address}/default', [AddressController::class, 'markDefault'])->middleware($required);
            Route::delete('account/addresses/{address}', [AddressController::class, 'destroy'])->middleware($required);
        });
    }
}
