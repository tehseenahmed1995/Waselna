<?php

declare(strict_types=1);

namespace App\Shared\Support\Correlation;

use InvalidArgumentException;
use Stringable;

/**
 * The Correlation_Identifier that ties a request, its log entries, its queued
 * Jobs, and its Realtime_Events together.
 *
 * Requirement 27.4 lets a client supply the identifier and requires the
 * Delivery_API to generate one when the header is absent; Requirement 27.5
 * propagates that single value everywhere the request reaches. A supplied value
 * is accepted only when it is safe to echo into a response header and into a
 * single-line JSON log entry, so the accepted alphabet excludes whitespace,
 * control characters, and header delimiters.
 *
 * Framework-free: the middleware, the log processor, and the queue payload hook
 * all pass this value object around rather than a raw string.
 */
final readonly class CorrelationId implements Stringable
{
    /**
     * The request and response header carrying the identifier.
     */
    public const HEADER = 'X-Correlation-Id';

    public const MIN_LENGTH = 8;

    public const MAX_LENGTH = 64;

    /**
     * Letters, digits, and the three separators a ULID, a UUID, or a trace
     * identifier needs. Nothing that could split a header or a log line.
     */
    private const PATTERN = '/^[A-Za-z0-9._-]++$/';

    private function __construct(public string $value) {}

    /**
     * @throws InvalidArgumentException when the value is not echo-safe
     */
    public static function fromString(string $value): self
    {
        $length = strlen($value);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A Correlation_Identifier must be %d to %d characters long; received %d.',
                self::MIN_LENGTH,
                self::MAX_LENGTH,
                $length,
            ));
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                'A Correlation_Identifier may hold only letters, digits, ".", "_", and "-".'
            );
        }

        return new self($value);
    }

    /**
     * The header-reading counterpart: an absent or unacceptable header yields
     * null so the caller generates a fresh identifier instead of rejecting the
     * request. Requirement 27.4 asks for generation, never for a 4xx.
     */
    public static function tryFromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        try {
            return self::fromString($trimmed);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
