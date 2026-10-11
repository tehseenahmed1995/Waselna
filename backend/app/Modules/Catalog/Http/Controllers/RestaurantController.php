<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Services\RestaurantDiscoveryService;
use Illuminate\Http\JsonResponse;

final class RestaurantController
{
    public function __construct(private readonly RestaurantDiscoveryService $discovery) {}

    public function index(): JsonResponse
    {
        $data = $this->discovery->search();

        return new JsonResponse(['data' => $data, 'meta' => ['total' => count($data), 'next_cursor' => null]], 200);
    }

    public function show(string $restaurant): JsonResponse
    {
        return new JsonResponse(['data' => $this->discovery->view($restaurant)], 200);
    }
}
