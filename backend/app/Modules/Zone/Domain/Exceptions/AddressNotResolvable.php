<?php

declare(strict_types=1);

namespace App\Modules\Zone\Domain\Exceptions;

use App\Modules\Zone\Contracts\Services\AddressResolver;
use App\Shared\Domain\Exceptions\DomainException;
use App\Shared\Domain\Exceptions\ErrorCatalogue;

/**
 * Raised when the {@see AddressResolver}
 * cannot match a submitted address or does not produce a coordinate pair within
 * the 3,000-millisecond budget (Requirements 5.6, 5.7, 32.14).
 *
 * It is a {@see DomainException} carrying the 422 catalogue code
 * `address_not_resolvable`, so the single exception handler renders it through
 * the one error-rendering pipeline (design "One error shape, one place that
 * renders it"); no Zone, Account, or Catalog Controller composes the body. The
 * caller stores no Delivery_Address and creates or changes no Restaurant_Branch
 * (Requirements 5.7, 32.14) because the failure is thrown before any write.
 */
final class AddressNotResolvable extends DomainException
{
    private const CODE = 'address_not_resolvable';

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function __construct(string $message, array $context = [])
    {
        ErrorCatalogue::assertCodeHasStatus(self::CODE, [422]);

        parent::__construct(self::CODE, $message, [], $context);
    }

    /**
     * The address resolver returned no match for the submitted address.
     */
    public static function noMatch(): self
    {
        return new self('The submitted address could not be resolved to a location.');
    }

    /**
     * The resolver did not produce a coordinate pair within the budget
     * (Requirement 5.6). The elapsed and budget milliseconds are logged, never
     * returned in the body.
     */
    public static function budgetExceeded(int $elapsedMilliseconds, int $budgetMilliseconds): self
    {
        return new self(
            'The submitted address could not be resolved to a location.',
            [
                'elapsed_ms' => $elapsedMilliseconds,
                'budget_ms' => $budgetMilliseconds,
            ],
        );
    }
}
