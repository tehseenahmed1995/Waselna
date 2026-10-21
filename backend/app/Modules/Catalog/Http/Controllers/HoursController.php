<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Contracts\Services\HoursManagementService;
use App\Modules\Catalog\Http\Requests\ReplaceHoursRequest;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;

/**
 * The Opening_Hours and Branch_Hours management endpoints (Requirements 11.7,
 * 11.8, 32.5, 32.6).
 *
 * The Controller holds no rules: it resolves the acting Platform user, hands the
 * Service the submitted schedule, and renders the result. Interval-rule
 * rejections (`invalid_hours`), the ownership decision (`forbidden`), and the
 * non-disclosing `restaurant_not_found` are all raised inside the Service and
 * rendered by the single exception pipeline, so no error body is composed here.
 */
final class HoursController
{
    public function __construct(private readonly HoursManagementService $hours) {}

    public function replaceOpeningHours(ReplaceHoursRequest $request, string $restaurant): JsonResponse
    {
        $this->hours->replaceOpeningHours(
            $this->actor($request),
            $restaurant,
            $request->submittedSchedule(),
        );

        return new JsonResponse(['data' => ['status' => 'ok']], 200);
    }

    public function replaceBranchHours(ReplaceHoursRequest $request, string $branch): JsonResponse
    {
        $this->hours->replaceBranchHours(
            $this->actor($request),
            $branch,
            $request->submittedSchedule(),
        );

        return new JsonResponse(['data' => ['status' => 'ok']], 200);
    }

    private function actor(ReplaceHoursRequest $request): ActorContext
    {
        $actor = $request->attributes->get('actor');

        if (! $actor instanceof ActorContext) {
            throw AuthorizationException::unauthenticated();
        }

        return $actor;
    }
}
