<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain;

/**
 * A Customer's password change (Requirements 31.5, 31.6).
 *
 * The presented Refresh_Token is carried so that it alone survives the
 * revocation that a successful change applies to every other Refresh_Token of
 * the Customer; it is null when the request arrives without one, in which case
 * every Refresh_Token is revoked.
 */
final readonly class ChangePasswordCommand
{
    public function __construct(
        public string $userId,
        public string $currentPassword,
        public string $newPassword,
        public ?string $presentedRefreshToken = null,
    ) {}
}
