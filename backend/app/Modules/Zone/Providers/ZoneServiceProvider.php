<?php

declare(strict_types=1);

namespace App\Modules\Zone\Providers;

use App\Modules\Zone\Boundary\ZoneAssignmentDirectory;
use App\Modules\Zone\Contracts\Services\AddressResolver;
use App\Modules\Zone\Contracts\Services\Containment;
use App\Modules\Zone\Contracts\Services\GeoJsonParser;
use App\Modules\Zone\Contracts\Services\GeoJsonPrinter;
use App\Modules\Zone\Contracts\Services\ServiceabilityService;
use App\Modules\Zone\Contracts\Services\ServiceableAddressResolver;
use App\Modules\Zone\Domain\CanonicalGeoJsonPrinter;
use App\Modules\Zone\Domain\RecursiveDescentGeoJsonParser;
use App\Modules\Zone\Services\DefaultServiceabilityService;
use App\Modules\Zone\Services\DefaultServiceableAddressResolver;
use App\Modules\Zone\Services\DeliveryZoneAssignmentDirectory;
use App\Modules\Zone\Services\LocalDeterministicAddressResolver;
use App\Modules\Zone\Services\PostGisContainment;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the container bindings owned by the Zone module (Delivery_Zones and geospatial containment).
 */
final class ZoneServiceProvider extends ServiceProvider
{
    /**
     * Service_Interface to Service_Implementation bindings owned by this module.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        GeoJsonParser::class => RecursiveDescentGeoJsonParser::class,
        GeoJsonPrinter::class => CanonicalGeoJsonPrinter::class,
        // Production containment runs through PostGIS ST_Covers; the in-memory
        // RayCastContainment is the agreement twin a property test pins it to
        // (Requirement 5.8, design Property 18).
        Containment::class => PostGisContainment::class,
        // The initial-phase AddressResolver: a local deterministic adapter that
        // integrates no paid geocoding service and makes no outbound call
        // (Requirements 5.6, 23.8, 43.7). A networked adapter can replace it
        // behind the same seam without a caller change.
        AddressResolver::class => LocalDeterministicAddressResolver::class,
    ];

    /**
     * Module_Boundary interfaces and external-dependency adapters owned by this module.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        // The serviceability seam consumed by Catalog discovery and Order
        // fulfilling-branch selection (Requirements 32.8, 32.9).
        ServiceabilityService::class => DefaultServiceabilityService::class,
        // The serviceability-gated resolution the Order module calls at checkout
        // (Requirements 5.6, 5.7, 5.9, 5.10, 32.14; design Property 19).
        ServiceableAddressResolver::class => DefaultServiceableAddressResolver::class,
        // The Delivery_Zone assignment seam the Catalog module calls when a
        // Restaurant_Operator assigns zones to a Restaurant_Branch (Requirements
        // 32.11, 32.12).
        ZoneAssignmentDirectory::class => DeliveryZoneAssignmentDirectory::class,
    ];

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
