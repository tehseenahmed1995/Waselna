<?php

declare(strict_types=1);

namespace App\Modules\Order\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Order\Services\RestaurantOrderService;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RestaurantOrderController
{
    public function __construct(private readonly RestaurantOrderService $service) {}

    public function queue(Request $request): JsonResponse
    {
        $data = $this->service->queue($this->actor($request));

        return new JsonResponse(['data' => $data, 'meta' => ['total' => count($data), 'next_cursor' => null]], 200);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'max:40'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'search' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $result = $this->service->history($this->actor($request), $validated);

        return new JsonResponse([
            'data' => $result['orders'],
            'meta' => ['total' => count($result['orders']), 'totals' => $result['totals']],
        ], 200);
    }

    public function accept(Request $request, string $order): JsonResponse
    {
        return new JsonResponse(['data' => $this->service->accept($this->actor($request), $order)], 200);
    }

    public function reject(Request $request, string $order): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new JsonResponse(['data' => $this->service->reject($this->actor($request), $order, (string) $validated['reason'])], 200);
    }

    public function ready(Request $request, string $order): JsonResponse
    {
        return new JsonResponse(['data' => $this->service->markReady($this->actor($request), $order)], 200);
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
