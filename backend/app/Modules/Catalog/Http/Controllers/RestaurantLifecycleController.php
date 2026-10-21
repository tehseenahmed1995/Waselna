<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Contracts\Services\RestaurantLifecycleService;
use App\Modules\Catalog\Domain\RestaurantStatusTransition;
use App\Modules\Catalog\Http\Requests\RestaurantStatusTransitionRequest;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Restaurant_Status lifecycle endpoints (Requirements 18.1, 18.2, 59.3–59.9).
 *
 * The Controller holds no rules: it resolves the acting Platform user, hands the
 * Service a {@see RestaurantStatusTransition}, and renders the result. The
 * per-edge authorization (which Role may make which transition and, for a
 * Restaurant_Operator, over its own Restaurant), the permitted-transition table,
 * the publication prerequisites, and the non-disclosing `restaurant_not_found`
 * are all decided inside the Service and rendered by the single exception
 * pipeline, so no error body is composed here. Ownership is deliberately not
 * pre-checked here because the permitted actor depends on the specific edge.
 */
final class RestaurantLifecycleController
{
    public function __construct(private readonly RestaurantLifecycleService $lifecycle) {}

    /**
     * Changes a Restaurant's Restaurant_Status to the submitted target
     * (Requirements 59.3, 59.5, 59.6; 18.1, 18.2).
     */
    public function transition(RestaurantStatusTransitionRequest $request, string $restaurant): JsonResponse
    {
        $view = $this->lifecycle->transitionStatus(new RestaurantStatusTransition(
            actor: $this->actor($request),
            restaurantId: $restaurant,
            toStatus: $request->targetStatus(),
        ));

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    /**
     * Submits a `draft` Restaurant for review on behalf of its
     * Restaurant_Operator (Requirement 59.4).
     */
    public function submitForReview(Request $request, string $restaurant): JsonResponse
    {
        $view = $this->lifecycle->submitForReview($this->actor($request), $restaurant);

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    private function actor(Request $request): ActorContext
    {
        $actor = $request->attributes->get('actor');

        if (! $actor instanceof ActorContext) {
            throw AuthorizationException::unauthenticated();
        }

        return $actor;
    }
}
