<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\AdminRestaurantService;
use App\Modules\Admin\Services\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminController
{
    public function __construct(
        private readonly AdminService $admin,
        private readonly AdminRestaurantService $restaurantAdmin,
    ) {}

    public function summary(): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->summary()], 200);
    }

    public function orders(Request $request): JsonResponse
    {
        $data = $this->admin->orders($request->only(['status', 'restaurant_id']));

        return new JsonResponse(['data' => $data, 'meta' => ['total' => count($data), 'next_cursor' => null]], 200);
    }

    public function restaurants(): JsonResponse
    {
        $data = $this->admin->restaurants();

        return new JsonResponse(['data' => $data, 'meta' => ['total' => count($data), 'next_cursor' => null]], 200);
    }

    public function couriers(): JsonResponse
    {
        $data = $this->admin->couriers();

        return new JsonResponse(['data' => $data, 'meta' => ['total' => count($data), 'next_cursor' => null]], 200);
    }

    public function deliveries(): JsonResponse
    {
        $data = $this->admin->deliveries();

        return new JsonResponse(['data' => $data, 'meta' => ['total' => count($data), 'next_cursor' => null]], 200);
    }

    public function createRestaurant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'cuisine' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'size:3'],
            'tax_rate_bp' => ['nullable', 'integer', 'min:0', 'max:3000'],
            'base_delivery_fee' => ['nullable', 'integer', 'min:0'],
            'address' => ['nullable', 'string', 'max:255'],
            'published' => ['nullable', 'boolean'],
        ]);

        return new JsonResponse(['data' => $this->restaurantAdmin->create($validated)], 201);
    }

    public function suspendRestaurant(string $restaurant): JsonResponse
    {
        return new JsonResponse(['data' => $this->restaurantAdmin->suspend($restaurant)], 200);
    }

    public function publishRestaurant(string $restaurant): JsonResponse
    {
        return new JsonResponse(['data' => $this->restaurantAdmin->publish($restaurant)], 200);
    }

    public function showRestaurant(string $restaurant): JsonResponse
    {
        return new JsonResponse(['data' => $this->restaurantAdmin->get($restaurant)], 200);
    }

    public function updateRestaurant(Request $request, string $restaurant): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'tax_rate_bp' => ['sometimes', 'integer', 'min:0', 'max:3000'],
            'base_delivery_fee' => ['sometimes', 'integer', 'min:0'],
        ]);

        return new JsonResponse(['data' => $this->restaurantAdmin->update($restaurant, $validated)], 200);
    }

    public function deleteRestaurant(string $restaurant): JsonResponse
    {
        $this->restaurantAdmin->delete($restaurant);

        return new JsonResponse(['data' => ['deleted' => true]], 200);
    }
}
