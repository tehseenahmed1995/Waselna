<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Contracts\Services\RestaurantLifecycleService;
use App\Modules\Catalog\Domain\PublishPrerequisites;
use App\Modules\Catalog\Domain\PublishReadiness;
use App\Modules\Catalog\Domain\RestaurantStatus;
use App\Modules\Catalog\Domain\RestaurantStatusTransition;
use App\Modules\Catalog\Domain\RestaurantView;
use App\Modules\Catalog\Events\RestaurantStatusChanged;
use App\Modules\Catalog\Models\Restaurant;
use App\Modules\Catalog\Repositories\MenuCacheInvalidator;
use App\Modules\Catalog\Repositories\RestaurantLifecycleRepository;
use App\Modules\Zone\Boundary\ZoneAssignmentDirectory;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ConflictException;
use App\Shared\Support\Clock;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * The Restaurant_Status lifecycle (Requirements 18.1, 18.2, 59.3–59.9).
 *
 * The service holds every rule; the {@see RestaurantLifecycleRepository} holds
 * the Catalog queries and the {@see ZoneAssignmentDirectory} boundary answers
 * the one Zone-owned fact. One transition runs inside one transaction that first
 * locks the Restaurant row, so the current status a transition reads cannot be
 * changed under it. The order of checks is fixed and matches the design's
 * rejection ordering: transition-table membership is evaluated before actor
 * permission, so a pair that is not a permitted edge is rejected with
 * `invalid_status_transition` regardless of who requested it, and only a
 * permitted edge reaches the `forbidden` decision. The publication prerequisites
 * of Requirement 59.5 are evaluated last, only for a permitted, authorized
 * transition whose target is `published`.
 *
 * Four outcomes follow a committed transition, all deferred to after the commit
 * so a rolled-back transition does none of them (design "post-commit events"):
 * the cached Menu and search read models for the Restaurant are invalidated
 * (Requirement 59.7), and one `restaurant.status_changed` domain event carrying
 * the previous and new status is published (Requirements 59.9, 18.1, 18.2). The
 * clock is injected so the change instant is deterministic under test
 * (Requirement 40.7). A rejected transition writes nothing, invalidates nothing,
 * and publishes nothing.
 */
final class RestaurantLifecycleServiceImplementation implements RestaurantLifecycleService
{
    public function __construct(
        private readonly RestaurantLifecycleRepository $restaurants,
        private readonly ZoneAssignmentDirectory $zones,
        private readonly MenuCacheInvalidator $cache,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
    ) {}

    public function transitionStatus(RestaurantStatusTransition $transition): RestaurantView
    {
        return DB::transaction(function () use ($transition): RestaurantView {
            $restaurant = $this->restaurants->findForUpdate($transition->restaurantId);

            if ($restaurant === null) {
                throw $this->notFound($transition->restaurantId);
            }

            $from = $restaurant->status;
            $to = $transition->toStatus;

            // 1. Membership before actor permission: a pair outside the five
            //    permitted edges is rejected with `invalid_status_transition`
            //    leaving the status unchanged, whoever asked (Requirements 59.3,
            //    59.6; design rejection ordering).
            if (! RestaurantStatus::canTransition($from, $to)) {
                throw ConflictException::because(
                    'invalid_status_transition',
                    'A Restaurant_Status may not move from '.$from.' to '.$to.'.',
                    ['restaurant_id' => $restaurant->id, 'from' => $from, 'to' => $to],
                );
            }

            // 2. Actor permission for this specific, permitted edge.
            $this->authorizeTransition($transition->actor, $restaurant->id, $from, $to);

            // 3. Publication prerequisites, only for a target of `published`
            //    (Requirement 59.5). An unmet prerequisite is named in the
            //    rejection and the status is left unchanged.
            if (RestaurantStatus::requiresPublishPrerequisites($to)) {
                $this->assertPublishable($restaurant->id);
            }

            $now = $this->clock->nowUtc();
            $restaurant->status = $to;
            $restaurant->updated_at = $now;
            $this->restaurants->save($restaurant);

            $this->afterStatusChange($restaurant->id, $from, $to, $transition->actor->userId, $now);

            return RestaurantView::fromModel($restaurant);
        });
    }

    public function submitForReview(ActorContext $actor, string $restaurantId): RestaurantView
    {
        return $this->transitionStatus(new RestaurantStatusTransition(
            actor: $actor,
            restaurantId: $restaurantId,
            toStatus: RestaurantStatus::PENDING_REVIEW,
        ));
    }

    /**
     * Decides whether the actor may make this permitted edge, by Role and, for a
     * Restaurant_Operator, ownership of the Restaurant (Requirements 59.4, 18.1,
     * 18.2; design authorization matrix).
     *
     *  - `draft` to `pending_review`: the Restaurant's own Restaurant_Operator
     *    submits for review, or an Administrator (Requirement 59.4).
     *  - `pending_review` to `draft`: the Restaurant's own Restaurant_Operator
     *    withdraws, or an Administrator returns it.
     *  - `pending_review` to `published`, `published` to `suspended`,
     *    `suspended` to `published`: an Administrator only — approval,
     *    suspension, and reinstatement are Administrator actions (Requirements
     *    18.1, 18.2).
     *
     * Every other actor is rejected with `forbidden`. The Role here is already
     * the one the route middleware narrowed to; this is the ownership half of
     * the decision and the per-edge split the middleware cannot express.
     */
    private function authorizeTransition(ActorContext $actor, string $restaurantId, string $from, string $to): void
    {
        if ($actor->hasRole('administrator')) {
            return;
        }

        $operatorEdge =
            ($from === RestaurantStatus::DRAFT && $to === RestaurantStatus::PENDING_REVIEW)
            || ($from === RestaurantStatus::PENDING_REVIEW && $to === RestaurantStatus::DRAFT);

        if (
            $operatorEdge
            && $actor->hasRole('restaurant_operator')
            && $actor->restaurantId === $restaurantId
        ) {
            return;
        }

        throw AuthorizationException::forbidden([
            'restaurant_id' => $restaurantId,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Rejects a publication whose prerequisites are not all met, naming every
     * unmet condition in the response context (Requirement 59.5). The three
     * facts are gathered — two from Catalog's own tables, the zone fact from the
     * Zone boundary — and handed to the framework-free rule, which decides
     * publishability and lists the unmet conditions.
     */
    private function assertPublishable(string $restaurantId): void
    {
        $readiness = new PublishReadiness(
            hasActiveBranch: $this->restaurants->hasActiveBranch($restaurantId),
            hasAvailableMenuItem: $this->restaurants->hasAvailableMenuItem($restaurantId),
            hasZoneOnActiveBranch: $this->zones->anyZoneOnActiveBranch($restaurantId),
        );

        $unmet = PublishPrerequisites::unmetConditions($readiness);

        if ($unmet !== []) {
            throw ConflictException::because(
                'restaurant_not_publishable',
                'This Restaurant does not satisfy every publication prerequisite.',
                [
                    'restaurant_id' => $restaurantId,
                    'unmet_conditions' => implode(',', $unmet),
                ],
            );
        }
    }

    /**
     * Registers the after-commit reactions to a committed status change: the
     * read-model invalidation (Requirement 59.7) and the single
     * `restaurant.status_changed` domain event (Requirements 59.9, 18.1, 18.2).
     *
     * `DB::afterCommit` fires each callback once the outermost transaction
     * commits and never on rollback, so a rejected or rolled-back transition
     * evicts nothing and publishes nothing. Each is registered once per changed
     * write, so each fires exactly once.
     */
    private function afterStatusChange(
        string $restaurantId,
        string $fromStatus,
        string $toStatus,
        string $actorId,
        \DateTimeImmutable $occurredAt,
    ): void {
        DB::afterCommit(function () use ($restaurantId): void {
            $this->cache->invalidateForRestaurant($restaurantId);
        });

        $event = new RestaurantStatusChanged(
            restaurantId: $restaurantId,
            fromStatus: $fromStatus,
            toStatus: $toStatus,
            actorId: $actorId,
            occurredAt: $occurredAt,
        );

        DB::afterCommit(function () use ($event): void {
            $this->events->dispatch($event);
        });
    }

    /**
     * The non-disclosing 404: identical whether the Restaurant does not exist or
     * is not visible to the actor, so the response never reveals its existence
     * (design "Non-disclosure rules").
     */
    private function notFound(string $restaurantId): AuthorizationException
    {
        return AuthorizationException::notFound(
            'restaurant_not_found',
            'No matching Restaurant was found.',
            ['restaurant_id' => $restaurantId],
        );
    }
}
