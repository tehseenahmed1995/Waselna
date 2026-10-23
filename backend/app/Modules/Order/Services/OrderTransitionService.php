<?php

declare(strict_types=1);

namespace App\Modules\Order\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Order\Domain\OrderStateMachine;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTimelineEntry;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ConflictException;
use App\Shared\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Drives an Order through the Order_State_Machine (Requirement 10). Edge
 * membership is asserted before actor permission: a transition that is not a
 * permitted edge is `invalid_state_transition` (409) whoever requests it; a
 * permitted edge requested by a disallowed actor is `forbidden` (403).
 *
 * A successful transition row-locks the Order, updates the state, and appends
 * exactly one Order_Timeline entry carrying the acting Platform user and a UTC
 * timestamp, all in one transaction.
 */
final class OrderTransitionService
{
    public function __construct(
        private readonly OrderStateMachine $machine,
        private readonly Clock $clock,
        private readonly NotificationService $notifications,
    ) {}

    public function transition(
        string $orderId,
        string $toState,
        string $actorRole,
        ?string $actorId,
        ?string $notes = null,
    ): Order {
        $order = DB::transaction(function () use ($orderId, $toState, $actorRole, $actorId, $notes): Order {
            /** @var Order|null $order */
            $order = Order::query()->lockForUpdate()->find($orderId);

            if ($order === null) {
                throw AuthorizationException::notFound('order_not_found', 'No Order matches that identifier.');
            }

            $from = $order->status;

            // Edge membership first (Requirement 10.4).
            if (! $this->machine->isEdge($from, $toState)) {
                throw ConflictException::because(
                    'invalid_state_transition',
                    sprintf('An Order cannot move from %s to %s.', $from, $toState),
                    ['from' => $from, 'to' => $toState],
                );
            }

            // Actor permission second (Requirement 10.5).
            if (! $this->machine->actorPermitted($from, $toState, $actorRole)) {
                throw AuthorizationException::forbidden(['from' => $from, 'to' => $toState]);
            }

            $now = $this->clock->nowUtc();

            $order->status = $toState;
            $order->updated_at = $now;
            $order->save();

            OrderTimelineEntry::query()->create([
                'id' => (string) Str::ulid(),
                'order_id' => $order->id,
                'new_status' => $toState,
                'actor_role' => $actorRole,
                'actor_id' => $actorId,
                'notes' => $notes,
                'created_at' => $now,
            ]);

            return $order;
        });

        // Notify the customer of the new state, after the transaction commits.
        $this->notifications->record(
            $order->customer_id,
            'order.'.$toState,
            'Order '.$order->order_number,
            $this->messageFor($toState),
            ['order_id' => $order->id, 'status' => $toState],
        );

        // When a new order lands (confirmed), notify the restaurant's operator
        // and staff so they can accept it from the kitchen queue.
        if ($toState === OrderStateMachine::CONFIRMED) {
            $this->notifyRestaurant($order);
        }

        return $order;
    }

    /**
     * Notify the Restaurant_Operator and all Restaurant_Staff of a restaurant
     * that a new order has been placed and needs their attention.
     */
    private function notifyRestaurant(Order $order): void
    {
        $users = User::query()
            ->where('restaurant_id', $order->restaurant_id)
            ->whereIn('role', ['restaurant_operator', 'restaurant_staff'])
            ->pluck('id');

        foreach ($users as $userId) {
            $this->notifications->record(
                (string) $userId,
                'order.new',
                'New order '.$order->order_number,
                'A new order has been placed. Head to the kitchen queue to accept it.',
                ['order_id' => $order->id],
            );
        }
    }

    private function messageFor(string $state): string
    {
        return match ($state) {
            OrderStateMachine::CONFIRMED => 'Order placed. Waiting for the restaurant to accept it.',
            OrderStateMachine::PREPARING => 'The restaurant accepted your order and is preparing it.',
            OrderStateMachine::READY_FOR_PICKUP => 'Your order is ready and waiting for a courier.',
            OrderStateMachine::ASSIGNED_TO_RIDER => 'A courier has been assigned to your order.',
            OrderStateMachine::PICKED_UP => 'Your order has been picked up.',
            OrderStateMachine::OUT_FOR_DELIVERY => 'Your order is on its way.',
            OrderStateMachine::DELIVERED => 'Your order has been delivered. Enjoy!',
            OrderStateMachine::REJECTED => 'Your order was rejected by the restaurant.',
            OrderStateMachine::PAYMENT_FAILED => 'Payment for your order failed.',
            OrderStateMachine::CANCELLED => 'Your order was cancelled.',
            default => 'Your order status changed to '.$state.'.',
        };
    }
}
