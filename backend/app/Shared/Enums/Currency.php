<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * The ISO 4217 currencies the Platform supports. Every monetary amount carries a
 * currency code (Requirement 7.8, 20.9); this enum is the authoritative list.
 * Extend as new markets are onboarded.
 */
enum Currency: string
{
    case USD = 'USD';
    case SAR = 'SAR';
    case AED = 'AED';
    case EUR = 'EUR';
    case GBP = 'GBP';

    public function label(): string
    {
        return match ($this) {
            self::USD => 'US Dollar',
            self::SAR => 'Saudi Riyal',
            self::AED => 'UAE Dirham',
            self::EUR => 'Euro',
            self::GBP => 'British Pound',
        };
    }

    public static function isSupported(string $code): bool
    {
        return self::tryFrom($code) !== null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
