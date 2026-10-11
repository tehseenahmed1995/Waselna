<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exceptions;

/**
 * The 422 family: a request whose shape was acceptable but whose content breaks
 * a declared rule or a business rule.
 *
 * Requirement 20.4 requires one entry per rejected field naming the field and
 * the rule violated, which is what `$fieldErrors` carries. Codes beyond
 * `validation_failed` (`item_unavailable`, `promotion_not_applicable`,
 * `restaurant_closed`, …) are the specific 422 codes of the catalogue.
 */
final class ValidationException extends DomainException
{
    /**
     * @param  array<string, list<string>>  $fieldErrors
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $errorCode,
        string $message,
        array $fieldErrors = [],
        array $context = [],
    ) {
        ErrorCatalogue::assertCodeHasStatus($errorCode, [422]);

        parent::__construct($errorCode, $message, $fieldErrors, $context);
    }

    /**
     * The generic schema rejection of Requirements 28.1 and 38.3.
     *
     * @param  array<string, list<string>>  $fieldErrors
     */
    public static function failed(array $fieldErrors, string $message = 'The request did not pass validation.'): self
    {
        return new self(ErrorCatalogue::VALIDATION_FAILED, $message, $fieldErrors);
    }

    /**
     * One rejected field and the rule it violated.
     */
    public static function forField(string $field, string $rule, ?string $message = null): self
    {
        return new self(
            ErrorCatalogue::VALIDATION_FAILED,
            $message ?? 'The request did not pass validation.',
            [$field => [$rule]],
        );
    }

    /**
     * A business rule rejection carrying one of the specific 422 codes.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function because(string $errorCode, string $message, array $context = []): self
    {
        return new self($errorCode, $message, [], $context);
    }
}
