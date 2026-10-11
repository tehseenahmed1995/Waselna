<?php

declare(strict_types=1);

namespace App\Modules\Order\Services;

use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Models\MenuItem;
use App\Modules\Catalog\Models\Restaurant;
use App\Modules\Catalog\Models\RestaurantBranch;
use App\Modules\Order\Domain\OrderStateMachine;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderLine;
use App\Modules\Order\Models\OrderTimelineEntry;
use App\Modules\Pricing\Services\PricingEngine;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The checkout use case (Requirement 8). Idempotent per (Customer,
 * Idempotency_Key): a repeat of the same key returns the Order the first call
 * created rather than placing a second.
 *
 * On success the Order is committed in `pending_payment`, with immutable
 * Order_Line and address snapshots, a stored price breakdown, one timeline
 * entry, and the Cart cleared. Payment then drives the Order to `confirmed`.
 */
final class CheckoutService
{
    private const MAX_ORDER_NUMBER_RETRIES = 5;

    public function __construct(
        private readonly CartService $carts,
        private readonly PricingEngine $pricing,
        private readonly OrderNumberGenerator $numbers,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    public function checkout(string $customerId, string $idempotencyKey, array $address, ?string $notes = null): array
    {
        // Idempotency: a prior Order under this key is replayed.
        $existing = DB::table('idempotency_keys')
            ->where('customer_id', $customerId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null && $existing->order_id !== null) {
            /** @var Order|null $order */
            $order = Order::query()->with('lines')->find($existing->order_id);

            if ($order !== null) {
                return $this->present($order);
            }
        }

        $cart = $this->carts->read($customerId);

        if ($cart['restaurant_id'] === null || $cart['lines'] === []) {
            throw ValidationException::because('item_unavailable', 'The Cart is empty.');
        }

        /** @var Restaurant|null $restaurant */
        $restaurant = Restaurant::query()->find($cart['restaurant_id']);

        if ($restaurant === null || $restaurant->status !== 'published') {
            throw ValidationException::because('restaurant_closed', 'The Restaurant is not accepting orders.');
        }

        $branch = $this->selectBranch($restaurant->id);

        // Re-validate availability and snapshot lines.
        $subtotalMinor = 0;
        $lineSnapshots = [];

        foreach ($cart['lines'] as $line) {
            /** @var MenuItem|null $item */
            $item = MenuItem::query()->find($line['menu_item_id']);

            if ($item === null || ! $item->is_available) {
                throw ValidationException::because('item_unavailable', sprintf('"%s" is no longer available.', $line['name']));
            }

            $quantity = (int) $line['quantity'];
            $unit = (int) $item->price_minor;
            $lineSubtotal = $unit * $quantity;
            $subtotalMinor += $lineSubtotal;

            $lineSnapshots[] = [
                'menu_item_id' => $item->id,
                'quantity' => $quantity,
                'unit_price_minor' => $unit,
                'subtotal_minor' => $lineSubtotal,
                'snapshot' => ['name' => $item->name, 'description' => $item->description],
            ];
        }

        $quote = $this->pricing->quote($restaurant->id, $subtotalMinor);

        return DB::transaction(function () use (
            $customerId,
            $idempotencyKey,
            $restaurant,
            $branch,
            $address,
            $notes,
            $lineSnapshots,
            $quote,
        ): array {
            $orderNumber = $this->uniqueOrderNumber();
            $now = $this->clock->nowUtc();

            /** @var Order $order */
            $order = Order::query()->create([
                'id' => (string) Str::ulid(),
                'customer_id' => $customerId,
                'restaurant_id' => $restaurant->id,
                'restaurant_branch_id' => $branch->id,
                'order_number' => $orderNumber,
                'status' => OrderStateMachine::PENDING_PAYMENT,
                'line_subtotal_minor' => $quote['line_subtotal']['amount'],
                'delivery_fee_minor' => $quote['delivery_fee']['amount'],
                'service_fee_minor' => $quote['service_fee']['amount'],
                'discount_amount_minor' => $quote['discount_amount']['amount'],
                'tax_amount_minor' => $quote['tax_amount']['amount'],
                'total_amount_minor' => $quote['order_total']['amount'],
                'currency' => $quote['currency'],
                'delivery_address_snapshot' => $address,
                'notes' => $notes,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($lineSnapshots as $line) {
                OrderLine::query()->create([
                    'id' => (string) Str::ulid(),
                    'order_id' => $order->id,
                    'menu_item_id' => $line['menu_item_id'],
                    'product_variant_id' => null,
                    'quantity' => $line['quantity'],
                    'unit_price_minor' => $line['unit_price_minor'],
                    'subtotal_minor' => $line['subtotal_minor'],
                    'currency' => $quote['currency'],
                    'menu_item_snapshot' => $line['snapshot'],
                    'variant_snapshot' => null,
                    'created_at' => $now,
                ]);
            }

            OrderTimelineEntry::query()->create([
                'id' => (string) Str::ulid(),
                'order_id' => $order->id,
                'new_status' => OrderStateMachine::PENDING_PAYMENT,
                'actor_role' => 'customer',
                'actor_id' => $customerId,
                'notes' => 'Order placed.',
                'created_at' => $now,
            ]);

            DB::table('idempotency_keys')->updateOrInsert(
                ['customer_id' => $customerId, 'idempotency_key' => $idempotencyKey],
                ['id' => (string) Str::ulid(), 'order_id' => $order->id, 'created_at' => $now],
            );

            $order->setRelation('lines', $order->lines()->get());

            return $this->present($order);
        });
    }

    private function selectBranch(string $restaurantId): RestaurantBranch
    {
        /** @var RestaurantBranch|null $branch */
        $branch = RestaurantBranch::query()
            ->where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($branch === null) {
            throw ValidationException::because('no_branch_available', 'No active Restaurant_Branch can fulfil this order.');
        }

        return $branch;
    }

    private function uniqueOrderNumber(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ORDER_NUMBER_RETRIES; $attempt++) {
            $candidate = $this->numbers->next();

            if (! $this->numbers->isTaken($candidate)) {
                return $candidate;
            }
        }

        throw ValidationException::failed(['order_number' => ['generation_failed']], 'Could not generate a unique Order_Number.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'restaurant_id' => $order->restaurant_id,
            'currency' => $order->currency,
            'breakdown' => [
                'line_subtotal' => ['amount' => $order->line_subtotal_minor, 'currency' => $order->currency],
                'delivery_fee' => ['amount' => $order->delivery_fee_minor, 'currency' => $order->currency],
                'service_fee' => ['amount' => $order->service_fee_minor, 'currency' => $order->currency],
                'tax_amount' => ['amount' => $order->tax_amount_minor, 'currency' => $order->currency],
                'discount_amount' => ['amount' => $order->discount_amount_minor, 'currency' => $order->currency],
                'order_total' => ['amount' => $order->total_amount_minor, 'currency' => $order->currency],
            ],
            'lines' => $order->lines->map(fn (OrderLine $line): array => [
                'id' => $line->id,
                'menu_item_id' => $line->menu_item_id,
                'quantity' => $line->quantity,
                'unit_price' => ['amount' => $line->unit_price_minor, 'currency' => $line->currency],
                'subtotal' => ['amount' => $line->subtotal_minor, 'currency' => $line->currency],
                'snapshot' => $line->menu_item_snapshot,
            ])->all(),
            'delivery_address' => $order->delivery_address_snapshot,
            'created_at' => $order->created_at,
        ];
    }
}
