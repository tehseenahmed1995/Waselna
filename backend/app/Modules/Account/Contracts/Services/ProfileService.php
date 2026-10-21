<?php

declare(strict_types=1);

namespace App\Modules\Account\Contracts\Services;

use App\Modules\Account\Domain\ProfileView;
use App\Modules\Account\Domain\RequestEmailChangeCommand;
use App\Modules\Account\Domain\UpdateProfileCommand;
use App\Modules\Auth\Domain\ChangePasswordCommand;

/**
 * The Account module's own-profile Service_Interface (Requirement 31.1–31.6,
 * 31.12–31.14).
 *
 * One Service_Interface seam over one implementation: the interface is what the
 * Controller and tests depend on, satisfying Requirement 38 without a second
 * production implementation.
 */
interface ProfileService
{
    /**
     * The authenticated Customer's own profile (Requirement 31.1).
     */
    public function getOwnProfile(string $userId): ProfileView;

    /**
     * Applies a partial update and returns the resulting profile
     * (Requirement 31.2); changes exactly the submitted fields.
     */
    public function updateProfile(UpdateProfileCommand $command): ProfileView;

    /**
     * Issues one email-change confirmation token, invalidating any previously
     * issued ones, and returns the plaintext token so it can be delivered
     * (Requirements 31.4, 31.14). The stored email address is unchanged until
     * confirmation.
     */
    public function requestEmailChange(RequestEmailChangeCommand $command): string;

    /**
     * Accepts a confirmation token, replacing the stored email address exactly
     * (Requirements 31.12, 31.13).
     */
    public function confirmEmailChange(string $userId, string $token): ProfileView;

    /**
     * Changes the Customer's password through the Auth boundary
     * (Requirements 31.5, 31.6).
     */
    public function changePassword(ChangePasswordCommand $command): void;
}
