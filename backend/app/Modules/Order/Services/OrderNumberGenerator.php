<?php

declare(strict_types=1);

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\Order;
use App\Shared\Support\Clock;

/**
 * Generates a well-formed, unique Order_Number of the form WAS-YYYY-NNNNNN
 * (Requirement 8.2). Collisions are retried; the caller bounds the retries.
 */
final class OrderNumberGenerator
{
    public function __construct(private readonly Clock $clock) {}

    public function next(): string
    {
        $year = $this->clock->nowUtc()->format('Y');
        $suffix = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return sprintf('WAS-%s-%s', $year, $suffix);
    }

    public function isTaken(string $orderNumber): bool
    {
        return Order::query()->where('order_number', $orderNumber)->exists();
    }
}
