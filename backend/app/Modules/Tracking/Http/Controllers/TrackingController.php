<?php

declare(strict_types=1);

namespace App\Modules\Tracking\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Tracking\Services\TrackingService;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TrackingController
{
    public function __construct(private readonly TrackingService $tracking) {}

    /**
     * The acting Courier reports its current location.
     */
    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $this->tracking->recordLocation(
            $this->actor($request),
            (float) $validated['latitude'],
            (float) $validated['longitude'],
        );

        return new JsonResponse(['data' => ['status' => 'ok']], 200);
    }

    /**
     * Live tracking for an Order (customer / restaurant / admin).
     */
    public function order(Request $request, string $order): JsonResponse
    {
        return new JsonResponse(['data' => $this->tracking->trackOrder($this->actor($request), $order)], 200);
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
