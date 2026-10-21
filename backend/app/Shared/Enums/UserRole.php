<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * The five Platform Roles (Requirement 1.2). A User holds exactly one. Backed by
 * the lowercase strings stored in `users.role` and checked by the authorization
 * middleware and ActorContext.
 */
enum UserRole: string
{
    case Customer = 'customer';
    case RestaurantOperator = 'restaurant_operator';
    case RestaurantStaff = 'restaurant_staff';
    case Courier = 'courier';
    case Administrator = 'administrator';

    /**
     * A human-friendly label for admin surfaces.
     */
    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::RestaurantOperator => 'Restaurant Operator',
            self::RestaurantStaff => 'Restaurant Staff',
            self::Courier => 'Courier',
            self::Administrator => 'Administrator',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
