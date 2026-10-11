<?php

declare(strict_types=1);

return [
    // The boot-time configuration check runs before every other binding
    // (Requirements 24.9, 44.8).
    App\Providers\EnvironmentServiceProvider::class,

    App\Providers\AppServiceProvider::class,

    // One service provider per domain module (Requirement 22.1, 22.6).
    App\Modules\Auth\Providers\AuthServiceProvider::class,
    App\Modules\Account\Providers\AccountServiceProvider::class,
    App\Modules\Catalog\Providers\CatalogServiceProvider::class,
    App\Modules\Zone\Providers\ZoneServiceProvider::class,
    App\Modules\Cart\Providers\CartServiceProvider::class,
    App\Modules\Pricing\Providers\PricingServiceProvider::class,
    App\Modules\Order\Providers\OrderServiceProvider::class,
    App\Modules\Payment\Providers\PaymentServiceProvider::class,
    App\Modules\Dispatch\Providers\DispatchServiceProvider::class,
    App\Modules\Rider\Providers\RiderServiceProvider::class,
    App\Modules\Tracking\Providers\TrackingServiceProvider::class,
    App\Modules\Notification\Providers\NotificationServiceProvider::class,
    App\Modules\Review\Providers\ReviewServiceProvider::class,
    App\Modules\Admin\Providers\AdminServiceProvider::class,
];
