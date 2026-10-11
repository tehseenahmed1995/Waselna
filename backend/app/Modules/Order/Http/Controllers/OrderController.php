<?php

declare(strict_types=1);

namespace App\Modules\Order\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Order\Services\CheckoutService;
use App\Modules\Order\Services\OrderQueryService;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderController
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly OrderQueryService $orders,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        if (! $actor->hasRole('customer')) {
            throw AuthorizationException::forbidden();
        }

        $idempotencyKey = $request->headers->get('Idempotency-Key');

        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            throw ValidationException::forField('Idempotency-Key', 'required');
        }

        $validated = $request->validate([
            'delivery_address' => ['required', 'array'],
            'delivery_address.line1' => ['required', 'string', 'max:255'],
            'delivery_address.city' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $data = $this->checkout->checkout(
            $actor->userId,
            trim($idempotencyKey),
            $validated['delivery_address'],
            $validated['notes'] ?? null,
        );

        return new JsonResponse(['data' => $data], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        if (! $actor->hasRole('customer')) {
            throw AuthorizationException::forbidden();
        }

        $data = $this->orders->listForCustomer($actor->userId);

        return new JsonResponse(['data' => $data, 'meta' => ['total' => count($data), 'next_cursor' => null]], 200);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        return new JsonResponse(['data' => $this->orders->viewForActor($this->actor($request), $order)], 200);
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
