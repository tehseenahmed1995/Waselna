<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * The availability states a Courier moves through. Mirrors the strings stored on
 * the rider profile; this enum is the shared vocabulary for the value and its
 * label across the API and admin/rider surfaces.
 */
enum RiderStatus: string
{
    case Offline = 'offline';
    case Online = 'online';
    case OnDelivery = 'on_delivery';

    public function label(): string
    {
        return match ($this) {
            self::Offline => 'Offline',
            self::Online => 'Online',
            self::OnDelivery => 'On delivery',
        };
    }
}
