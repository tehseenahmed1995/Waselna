<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exceptions;

use RuntimeException;

/**
 * The base of every failure domain code raises deliberately.
 *
 * Design decision recorded in "One error shape, one place that renders it":
 * domain code throws a typed exception carrying a stable code, an HTTP status,
 * optional per-field details, and optional context that is logged but never
 * returned. A single exception handler renders it, so no Controller composes an
 * error body and Requirement 20.4 is satisfied for every endpoint at once.
 *
 * Framework-free: nothing here touches an HTTP request, a response, or a
 * container, which keeps it usable from `Domain/` code and from a queued Job.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $fieldErrors  rejected field to the rules it violated
     * @param  array<string, scalar|null>  $context  diagnostics for the log, never for the body
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $fieldErrors = [],
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * A stable, documented code from the catalogue.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The status the catalogue records for this code.
     */
    public function httpStatus(): int
    {
        return ErrorCatalogue::statusFor($this->errorCode);
    }

    /**
     * @return array<string, list<string>>
     */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }

    /**
     * Logged alongside the failure, excluded from the response body. Callers put
     * identifiers and state names here, never a password, a token, or payment
     * instrument data (Requirement 27.7).
     *
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
