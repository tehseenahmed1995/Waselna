<?php

declare(strict_types=1);

namespace App\Modules\Review\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Review\Services\ReviewService;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReviewController
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function submit(Request $request, string $order): JsonResponse
    {
        $actor = $request->attributes->get('actor');

        if (! $actor instanceof ActorContext) {
            throw AuthorizationException::unauthenticated();
        }

        if (! $actor->hasRole('customer')) {
            throw AuthorizationException::forbidden();
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $data = $this->reviews->submit($actor, $order, (int) $validated['rating'], $validated['comment'] ?? null);

        return new JsonResponse(['data' => $data], 201);
    }

    public function forRestaurant(string $restaurant): JsonResponse
    {
        $data = $this->reviews->forRestaurant($restaurant);

        return new JsonResponse(['data' => $data, 'meta' => ['total' => count($data), 'next_cursor' => null]], 200);
    }
}
