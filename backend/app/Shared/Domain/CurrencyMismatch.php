<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use InvalidArgumentException;

/**
 * Raised when two monetary values of different currencies are combined.
 *
 * Requirement 20.9 pairs every monetary value with an ISO 4217 code, so adding
 * amounts across currencies is a programming error rather than a user error and
 * must never silently produce a total.
 */
final class CurrencyMismatch extends InvalidArgumentException
{
    public static function between(string $expected, string $actual): self
    {
        return new self(sprintf(
            'Monetary values must share one currency; received %s and %s.',
            $expected,
            $actual,
        ));
    }
}
