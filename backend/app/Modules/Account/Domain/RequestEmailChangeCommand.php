<?php

declare(strict_types=1);

namespace App\Modules\Account\Domain;

/**
 * A Customer's request to change the account email address (Requirement 31.4).
 *
 * Carries only the proposed new address; the current address and identity come
 * from the authenticated actor, never from the request body.
 */
final readonly class RequestEmailChangeCommand
{
    public function __construct(
        public string $userId,
        public string $newEmail,
    ) {}
}
