<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Auth\Http\Requests\InviteStaffRequest;
use App\Modules\Auth\Services\StaffService;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Restaurant_Staff management for a Restaurant_Operator. The Controller resolves
 * the acting operator and hands the Service the request; ownership scoping, the
 * staff limit, and the non-disclosing 404 live in the Service.
 */
final class StaffController
{
    public function __construct(private readonly StaffService $staff) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->staff->list($this->actor($request));

        return new JsonResponse(['data' => $data, 'meta' => ['total' => count($data)]], 200);
    }

    public function store(InviteStaffRequest $request): JsonResponse
    {
        $view = $this->staff->invite($this->actor($request), $request->validated());

        return new JsonResponse(['data' => $view], 201);
    }

    public function destroy(Request $request, string $staff): JsonResponse
    {
        $this->staff->remove($this->actor($request), $staff);

        return new JsonResponse(['data' => ['status' => 'ok']], 200);
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
