<?php

declare(strict_types=1);

namespace App\Modules\Rider\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Rider\Services\RiderService;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RiderController
{
    public function __construct(private readonly RiderService $rider) {}

    public function profile(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->rider->profile($this->actor($request))], 200);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_type' => ['sometimes', 'string'],
            'phone' => ['sometimes', 'string', 'max:32'],
        ]);

        return new JsonResponse(['data' => $this->rider->updateProfile($this->actor($request), $validated)], 200);
    }

    public function goOnline(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->rider->goOnline($this->actor($request))], 200);
    }

    public function goOffline(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->rider->goOffline($this->actor($request))], 200);
    }

    private function actor(Request $request): ActorContext
    {
        $actor = $request->attributes->get('actor');

        if (! $actor instanceof ActorContext) {
            throw AuthorizationException::unauthenticated();
        }

        if (! $actor->hasRole('courier')) {
            throw AuthorizationException::forbidden();
        }

        return $actor;
    }
}
