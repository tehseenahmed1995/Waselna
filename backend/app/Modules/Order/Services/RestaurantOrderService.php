<?php

declare(strict_types=1);

namespace App\Modules\Order\Services;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Dispatch\Services\DispatchService;
use App\Modules\Order\Domain\OrderStateMachine;
use App\Modules\Order\Models\Order;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ValidationException;

/**
 * The Restaurant-facing order queue and handling actions (Requirement 12):
 * accept (confirmed → preparing), reject (confirmed → rejected), mark ready
 * (preparing → ready_for_pickup). All actions and reads are scoped to the
 * acting operator/staff member's Restaurant.
 */
final class RestaurantOrderService
{
    public function __construct(
        private readonly OrderTransitionService $transitions,
        private readonly OrderQueryService $queries,
        private readonly DispatchService $dispatch,
    ) {}

    /**
     * The active order queue for the operator's Restaurant, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(ActorContext $actor): array
    {
        $restaurantId = $this->restaurantId($actor);

        return Order::query()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('status', [
                OrderStateMachine::CONFIRMED,
                OrderStateMachine::PREPARING,
                OrderStateMachine::READY_FOR_PICKUP,
                OrderStateMachine::ASSIGNED_TO_RIDER,
                OrderStateMachine::PICKED_UP,
                OrderStateMachine::OUT_FOR_DELIVERY,
            ])
            ->with('lines')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Order $order): array => $this->summary($order))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function accept(ActorContext $actor, string $orderId): array
    {
        $this->assertOwns($actor, $orderId);
        $this->transitions->transition($orderId, OrderStateMachine::PREPARING, $actor->role, $actor->userId, 'Order accepted.');

        return $this->queries->viewForActor($actor, $orderId);
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(ActorContext $actor, string $orderId, string $reason): array
    {
        if (trim($reason) === '') {
            throw ValidationException::forField('reason', 'required');
        }

        $this->assertOwns($actor, $orderId);
        $this->transitions->transition($orderId, OrderStateMachine::REJECTED, $actor->role, $actor->userId, $reason);

        return $this->queries->viewForActor($actor, $orderId);
    }

    /**
     * @return array<string, mixed>
     */
    public function markReady(ActorContext $actor, string $orderId): array
    {
        $this->assertOwns($actor, $orderId);
        $this->transitions->transition($orderId, OrderStateMachine::READY_FOR_PICKUP, $actor->role, $actor->userId, 'Order ready for pickup.');

        // A ready Order becomes an available Delivery_Job for Couriers.
        $order = Order::query()->find($orderId);
        if ($order !== null) {
            $this->dispatch->createForOrder($order);
        }

        return $this->queries->viewForActor($actor, $orderId);
    }

    private function assertOwns(ActorContext $actor, string $orderId): void
    {
        $restaurantId = $this->restaurantId($actor);

        $owns = Order::query()
            ->where('id', $orderId)
            ->where('restaurant_id', $restaurantId)
            ->exists();

        if (! $owns) {
            throw AuthorizationException::notFound('order_not_found', 'No Order matches that identifier.');
        }
    }

    private function restaurantId(ActorContext $actor): string
    {
        if (! $actor->hasRole('restaurant_operator', 'restaurant_staff') || $actor->restaurantId === null) {
            throw AuthorizationException::forbidden();
        }

        return $actor->restaurantId;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'currency' => $order->currency,
            'order_total' => ['amount' => $order->total_amount_minor, 'currency' => $order->currency],
            'line_count' => $order->lines->count(),
            'lines' => $order->lines->map(fn ($l): array => [
                'quantity' => $l->quantity,
                'name' => $l->menu_item_snapshot['name'] ?? 'Item',
            ])->all(),
            'created_at' => $order->created_at,
        ];
    }
}
