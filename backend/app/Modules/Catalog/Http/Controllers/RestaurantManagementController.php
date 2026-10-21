<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Contracts\Services\RestaurantManagementService;
use App\Modules\Catalog\Domain\BranchCommand;
use App\Modules\Catalog\Domain\RestaurantProfileCommand;
use App\Modules\Catalog\Http\Requests\BranchRequest;
use App\Modules\Catalog\Http\Requests\BranchZoneAssignmentRequest;
use App\Modules\Catalog\Http\Requests\RestaurantProfileRequest;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Restaurant profile and Restaurant_Branch management endpoints (task 7.6;
 * Requirement 32). The Controller holds no rules: it resolves the acting
 * Platform user, hands the Service a command, and renders the result.
 *
 * Ownership is decided inside the Service, not here, so the response for a
 * Restaurant another Operator owns is identical to one for a Restaurant that
 * does not exist — the non-disclosing `restaurant_not_found` of Requirement
 * 32.12. The Role gate itself is applied by the
 * `AuthorizeRole:restaurant_operator,administrator` middleware on the routes.
 * Every documented rejection — the time-zone and cuisine-tag rules, the
 * 50-branch and 20-zone bounds, an unresolvable address, the non-disclosing 404
 * — is raised inside the Service and rendered by the single exception pipeline,
 * so no error body is composed here.
 */
final class RestaurantManagementController
{
    public function __construct(private readonly RestaurantManagementService $restaurants) {}

    public function showProfile(Request $request, string $restaurant): JsonResponse
    {
        $view = $this->restaurants->viewProfile($this->actor($request), $restaurant);

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    public function updateProfile(RestaurantProfileRequest $request, string $restaurant): JsonResponse
    {
        $view = $this->restaurants->updateProfile(
            $this->actor($request),
            RestaurantProfileCommand::fromValidated($restaurant, $request->validated()),
        );

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    public function storeBranch(BranchRequest $request, string $restaurant): JsonResponse
    {
        $view = $this->restaurants->createBranch(
            $this->actor($request),
            BranchCommand::fromValidated($restaurant, $request->validated()),
        );

        return new JsonResponse(['data' => $view->toArray()], 201);
    }

    public function updateBranch(BranchRequest $request, string $restaurant, string $branch): JsonResponse
    {
        $view = $this->restaurants->updateBranch(
            $this->actor($request),
            BranchCommand::fromValidated($restaurant, $request->validated(), $branch),
        );

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    public function activateBranch(Request $request, string $restaurant, string $branch): JsonResponse
    {
        $view = $this->restaurants->setBranchActive($this->actor($request), $restaurant, $branch, true);

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    public function deactivateBranch(Request $request, string $restaurant, string $branch): JsonResponse
    {
        $view = $this->restaurants->setBranchActive($this->actor($request), $restaurant, $branch, false);

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    public function assignZones(BranchZoneAssignmentRequest $request, string $restaurant, string $branch): JsonResponse
    {
        /** @var list<string> $zoneIds */
        $zoneIds = array_values(array_map('strval', (array) $request->validated('zone_ids', [])));

        $view = $this->restaurants->assignZones($this->actor($request), $restaurant, $branch, $zoneIds);

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    /**
     * The acting Platform user the authentication middleware placed on the
     * request. Absent it, the request never passed the bearer gate, so this is
     * the unauthenticated rejection rather than a crash.
     */
    private function actor(Request $request): ActorContext
    {
        $actor = $request->attributes->get('actor');

        if (! $actor instanceof ActorContext) {
            throw AuthorizationException::unauthenticated();
        }

        return $actor;
    }
}
