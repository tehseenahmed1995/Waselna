<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exceptions;

/**
 * The 409 family: the request is well formed and permitted, but the current
 * state of the record refuses it.
 *
 * Every code of the catalogue's 409 row lands here — `invalid_state_transition`,
 * `cart_restaurant_conflict`, `job_already_assigned`, `last_active_branch`, and
 * the rest. Requirement 20.3 fixes the status; the ordering rule of the design's
 * "Rejection ordering" section fixes when a 409 wins over a 403 (Order transition
 * edge membership is evaluated before actor permission, Requirements 10.4, 10.5).
 */
final class ConflictException extends DomainException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(string $errorCode, string $message, array $context = [])
    {
        ErrorCatalogue::assertCodeHasStatus($errorCode, [409]);

        parent::__construct($errorCode, $message, [], $context);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function because(string $errorCode, string $message, array $context = []): self
    {
        return new self($errorCode, $message, $context);
    }
}
