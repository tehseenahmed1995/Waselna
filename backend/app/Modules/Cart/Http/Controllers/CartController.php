<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Cart\Services\CartService;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CartController
{
    public function __construct(private readonly CartService $cart) {}

    public function show(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->cart->view($this->customerId($request))], 200);
    }

    public function add(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'menu_item_id' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $data = $this->cart->addItem(
            $this->customerId($request),
            (string) $validated['menu_item_id'],
            (int) $validated['quantity'],
        );

        return new JsonResponse(['data' => $data], 200);
    }

    public function updateLine(Request $request, string $line): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $data = $this->cart->setQuantity(
            $this->customerId($request),
            $line,
            (int) $validated['quantity'],
        );

        return new JsonResponse(['data' => $data], 200);
    }

    public function removeLine(Request $request, string $line): JsonResponse
    {
        return new JsonResponse(['data' => $this->cart->removeLine($this->customerId($request), $line)], 200);
    }

    private function customerId(Request $request): string
    {
        $actor = $request->attributes->get('actor');

        if (! $actor instanceof ActorContext) {
            throw AuthorizationException::unauthenticated();
        }

        if (! $actor->hasRole('customer')) {
            throw AuthorizationException::forbidden();
        }

        return $actor->userId;
    }
}
