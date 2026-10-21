<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminController
{
    public function __construct(private readonly AdminService $admin) {}

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
}
