<?php

declare(strict_types=1);

/*
 * Structural guarantees of the modular monolith skeleton.
 *
 * Requirement 22.1 (exactly 14 modules), 22.7 / 38.1 (Backend_Layer_Chain
 * directories inside every module), 22.6 (one service provider per module for
 * container bindings), 22.5 (framework-free Domain directory present).
 */

dataset('modules', [
    'Auth',
    'Account',
    'Catalog',
    'Zone',
    'Cart',
    'Pricing',
    'Order',
    'Payment',
    'Dispatch',
    'Rider',
    'Tracking',
    'Notification',
    'Review',
    'Admin',
]);

it('registers the service provider of every module', function (string $module): void {
    $provider = sprintf('App\\Modules\\%s\\Providers\\%sServiceProvider', $module, $module);

    expect(app()->getLoadedProviders())->toHaveKey($provider);
})->with('modules');

it('gives every module the Backend_Layer_Chain directory tree', function (string $module): void {
    $directories = [
        'Http/Controllers',
        'Http/Requests',
        'Http/Resources',
        'Contracts/Services',
        'Services',
        'Repositories',
        'Models',
        'Domain',
        'Events',
        'Listeners',
        'Jobs',
        'Boundary',
        'Providers',
    ];

    foreach ($directories as $directory) {
        expect(is_dir(app_path("Modules/{$module}/{$directory}")))
            ->toBeTrue("app/Modules/{$module}/{$directory} is missing");
    }
})->with('modules');

it('contains no module directory beyond the fourteen named modules', function (): void {
    $present = array_map(
        static fn (string $path): string => basename($path),
        glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [],
    );

    sort($present);

    expect($present)->toBe([
        'Account',
        'Admin',
        'Auth',
        'Cart',
        'Catalog',
        'Dispatch',
        'Notification',
        'Order',
        'Payment',
        'Pricing',
        'Review',
        'Rider',
        'Tracking',
        'Zone',
    ]);
});

it('provides the shared kernel directory tree', function (): void {
    foreach (['Domain', 'Http/Middleware', 'Support'] as $directory) {
        expect(is_dir(app_path("Shared/{$directory}")))
            ->toBeTrue("app/Shared/{$directory} is missing");
    }
});
