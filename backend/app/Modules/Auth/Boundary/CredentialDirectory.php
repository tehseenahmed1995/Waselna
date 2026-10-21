<?php

declare(strict_types=1);

namespace App\Modules\Auth\Boundary;

use App\Modules\Auth\Domain\ChangePasswordCommand;

/**
 * Module_Boundary the Auth module exposes for the credential operations other
 * modules trigger but do not own.
 *
 * The Account module's email-change flow lives in Account, but the password
 * hash and the Refresh_Tokens live in Auth's `users` and `refresh_tokens`
 * tables. Account's ProfileService therefore reaches password change through
 * this boundary rather than through Auth's SQL, which keeps the ownership rule
 * of Requirement 22 intact (and will keep Deptrac green once the module layers
 * of task 32.1 land).
 */
interface CredentialDirectory
{
    /**
     * Changes the Customer's password against the stored hash and revokes every
     * Refresh_Token except the presented one (Requirements 31.5, 31.6).
     */
    public function changePassword(ChangePasswordCommand $command): void;
}
