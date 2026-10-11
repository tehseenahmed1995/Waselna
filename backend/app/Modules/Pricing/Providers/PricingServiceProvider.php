<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the container bindings owned by the Pricing module (the Pricing_Engine and Promotion evaluation).
 */
final class PricingServiceProvider extends ServiceProvider
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
        //
    }
}
