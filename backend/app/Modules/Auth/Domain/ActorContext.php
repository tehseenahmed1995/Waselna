<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain;

/**
 * The acting Platform user resolved from the Access_Token on every request,
 * built from the database record so the Role is authoritative (Requirement 2.10).
 */
final readonly class ActorContext
{
    public function __construct(
        public string $userId,
        public string $role,
        public string $accountStatus,
        public ?string $restaurantId = null,
        public ?string $branchId = null,
    ) {}

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isSuspended(): bool
    {
        return $this->accountStatus === 'suspended';
    }
}
