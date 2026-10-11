<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Payment\Services\PaymentService;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentController
{
    public function __construct(private readonly PaymentService $payments) {}

    public function pay(Request $request, string $order): JsonResponse
    {
        $actor = $request->attributes->get('actor');

        if (! $actor instanceof ActorContext) {
            throw AuthorizationException::unauthenticated();
        }

        if (! $actor->hasRole('customer')) {
            throw AuthorizationException::forbidden();
        }

        return new JsonResponse(['data' => $this->payments->pay($actor->userId, $order)], 201);
    }
}
