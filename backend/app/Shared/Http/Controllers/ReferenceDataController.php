<?php

declare(strict_types=1);

namespace App\Shared\Http\Controllers;

use App\Shared\Enums\Currency;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\RestaurantStatus;
use App\Shared\Enums\RiderStatus;
use App\Shared\Enums\UserRole;
use Illuminate\Http\JsonResponse;

/**
 * Serves the Platform's shared vocabularies (roles, statuses, currencies) as the
 * single source of truth for the Web_Client. The UI fetches this once and reads
 * every label and option from it, so a value or its display text is defined only
 * here in the backend enums and never duplicated in the frontend.
 *
 * Public and cacheable: this is static reference data, not tenant data.
 */
final class ReferenceDataController
{
    public function index(): JsonResponse
    {
        return (new JsonResponse([
            'data' => [
                'roles' => $this->options(UserRole::cases()),
                'order_statuses' => $this->options(OrderStatus::cases()),
                'restaurant_statuses' => $this->options(RestaurantStatus::cases()),
                'rider_statuses' => $this->options(RiderStatus::cases()),
                'currencies' => $this->options(Currency::cases()),
            ],
        ], 200))->setPublic()->setMaxAge(3600);
    }

    /**
     * @param  array<int, UserRole|OrderStatus|RestaurantStatus|RiderStatus|Currency>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(
            static fn (UserRole|OrderStatus|RestaurantStatus|RiderStatus|Currency $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        );
    }
}
