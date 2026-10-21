<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Modules\Auth\Domain\ActorContext;

/**
 * The command to change one Restaurant's Restaurant_Status (Requirements 18.1,
 * 18.2, 59.3–59.6).
 *
 * It carries the acting Platform user so the service — not the Controller —
 * decides whether the actor may make this particular transition: an
 * Administrator approves (`pending_review` to `published`), suspends
 * (`published` to `suspended`), and reinstates (`suspended` to `published`),
 * while a Restaurant_Operator of the Restaurant submits for review (`draft` to
 * `pending_review`) and withdraws (`pending_review` to `draft`). The target
 * status is the requested `to`; the current status is read from the locked row
 * inside the transaction so the decision cannot race a concurrent change.
 */
final readonly class RestaurantStatusTransition
{
    public function __construct(
        public ActorContext $actor,
        public string $restaurantId,
        public string $toStatus,
    ) {}
}
