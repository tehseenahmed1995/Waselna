<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exceptions;

/**
 * The identity family: 401 when the caller is not established, 403 when the
 * caller is established but not permitted, 404 where naming the record would
 * itself disclose it.
 *
 * The 404 members exist here on purpose. The design's "Non-disclosure rules"
 * section requires `order_not_found`, `address_not_found`, `delivery_not_found`
 * to read identically whether the record is absent or belongs to another
 * Platform user (Requirements 2.8, 31.11, 33.2, 35.9); routing both paths through
 * one exception family is what keeps them from drifting apart.
 */
final class AuthorizationException extends DomainException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(string $errorCode, string $message, array $context = [])
    {
        ErrorCatalogue::assertCodeHasStatus($errorCode, [401, 403, 404]);

        parent::__construct($errorCode, $message, [], $context);
    }

    /**
     * Absent or malformed credentials (Requirement 2.2).
     */
    public static function unauthenticated(): self
    {
        return new self('unauthenticated', 'This endpoint requires an Access_Token.');
    }

    /**
     * A syntactically valid Access_Token that is revoked or expired
     * (Requirement 1.11).
     */
    public static function invalidToken(): self
    {
        return new self('invalid_token', 'The presented Access_Token is no longer valid.');
    }

    /**
     * An established caller whose Role or ownership does not permit the action
     * (Requirement 2.3).
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function forbidden(array $context = []): self
    {
        return new self('forbidden', 'This action is not permitted for the acting Platform user.', $context);
    }

    /**
     * A suspended account (Requirement 2.10).
     */
    public static function accountSuspended(): self
    {
        return new self('account_suspended', 'This account is suspended.');
    }

    /**
     * The non-disclosing 404: identical for "does not exist" and "not yours".
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notFound(string $errorCode, string $message, array $context = []): self
    {
        ErrorCatalogue::assertCodeHasStatus($errorCode, [404]);

        return new self($errorCode, $message, $context);
    }
}
