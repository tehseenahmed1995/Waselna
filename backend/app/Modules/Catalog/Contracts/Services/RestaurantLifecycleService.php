<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts\Services;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Domain\RestaurantStatusTransition;
use App\Modules\Catalog\Domain\RestaurantView;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ConflictException;

/**
 * The Catalog module's Restaurant_Status lifecycle Service_Interface
 * (Requirements 18.1, 18.2, 59.3–59.9).
 *
 * One seam over one implementation: the Controller and tests depend on this
 * interface, satisfying Requirement 38 without a second production
 * implementation. The service, not the Controller, holds every rule — the
 * permitted-transition table, the publication prerequisites, the actor's
 * permission to make a given transition, the post-commit event, and the
 * read-model invalidation.
 */
interface RestaurantLifecycleService
{
    /**
     * Transitions one Restaurant's Restaurant_Status to the requested target in
     * one transaction, returning the Restaurant with its new status
     * (Requirements 59.3–59.9).
     *
     * The current status is read from the locked Restaurant row; the requested
     * pair is checked against the five permitted edges (Requirement 59.3); a
     * target of `published` additionally requires the three publication
     * prerequisites of Requirement 59.5. On success the status is updated, the
     * affected cached Menu and search read models are invalidated after commit
     * (Requirement 59.7), and one `restaurant.status_changed` domain event is
     * published after commit carrying the previous and new status
     * (Requirements 59.9, 18.1, 18.2).
     *
     * @throws AuthorizationException with `forbidden` where the actor may not
     *                                make this transition, or the non-disclosing `restaurant_not_found`
     *                                where no such Restaurant exists.
     * @throws ConflictException with `invalid_status_transition` where the pair
     *                           is not a permitted edge, or `restaurant_not_publishable` naming
     *                           every unmet condition where a publication prerequisite fails; the
     *                           stored status is left unchanged in both cases.
     */
    public function transitionStatus(RestaurantStatusTransition $transition): RestaurantView;

    /**
     * Transitions a `draft` Restaurant to `pending_review` on behalf of its
     * Restaurant_Operator (Requirement 59.4). A convenience over
     * {@see transitionStatus()} with the target fixed to `pending_review`; the
     * same transition table, authorization, and post-commit reactions apply.
     *
     * @throws AuthorizationException as {@see transitionStatus()}.
     * @throws ConflictException as {@see transitionStatus()}.
     */
    public function submitForReview(ActorContext $actor, string $restaurantId): RestaurantView;
}
