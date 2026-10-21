<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts\Services;

use App\Modules\Auth\Domain\ActorContext;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ValidationException;

/**
 * The Catalog module's hours management Service_Interface (Requirements 11.7,
 * 11.8, 32.5, 32.6).
 *
 * One seam over one implementation: the Controller and tests depend on this
 * interface, satisfying Requirement 38 without a second production
 * implementation. Both operations take the acting Platform user so the service,
 * not the Controller, decides whether the actor may manage the owner's hours — a
 * Restaurant_Operator over its own Restaurant and an Administrator over any
 * (design "module ownership" and the authorization matrix).
 *
 * Each operation replaces the owner's complete stored weekly schedule with the
 * submitted intervals in one transaction, all-or-nothing; a submission breaking
 * an interval rule is rejected with `invalid_hours` and changes nothing
 * (Requirement 32.6, 11.8).
 */
interface HoursManagementService
{
    /**
     * Replaces a Restaurant's complete Opening_Hours with the submitted weekly
     * schedule in one transaction (Requirement 11.7).
     *
     * @param  array<string, list<array{start_minute: int, end_minute: int}>>  $submitted
     *                                                                                     the submitted schedule, shape-validated by the Form_Request; interval
     *                                                                                     rules are enforced here.
     *
     * @throws AuthorizationException with `forbidden` where the actor may not
     *                                manage the Restaurant, or `restaurant_not_found` where no such
     *                                Restaurant exists (non-disclosing).
     * @throws ValidationException with `invalid_hours` and one per-field detail
     *                             for each violating interval; nothing is stored.
     */
    public function replaceOpeningHours(ActorContext $actor, string $restaurantId, array $submitted): void;

    /**
     * Replaces a Restaurant_Branch's complete Branch_Hours with the submitted
     * weekly schedule in one transaction (Requirement 32.5).
     *
     * @param  array<string, list<array{start_minute: int, end_minute: int}>>  $submitted
     *                                                                                     the submitted schedule, shape-validated by the Form_Request; interval
     *                                                                                     rules are enforced here.
     *
     * @throws AuthorizationException with `forbidden` where the actor may not
     *                                manage the Restaurant_Branch, or `restaurant_not_found` where no such
     *                                Restaurant_Branch exists (non-disclosing).
     * @throws ValidationException with `invalid_hours` and one per-field detail
     *                             for each violating interval; nothing is stored.
     */
    public function replaceBranchHours(ActorContext $actor, string $branchId, array $submitted): void;
}
