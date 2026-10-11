<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain;

/**
 * The Order_State_Machine (Requirement 10): the permitted transitions over the
 * 12 Order_States, each edge carrying the set of actor roles permitted to drive
 * it. Edge membership is evaluated before actor permission so a transition that
 * is not an edge at all is rejected as `invalid_state_transition` regardless of
 * who requests it.
 *
 * Framework-free: pure data and pure functions.
 */
final class OrderStateMachine
{
    public const PENDING_PAYMENT = 'pending_payment';

    public const CONFIRMED = 'confirmed';

    public const PREPARING = 'preparing';

    public const READY_FOR_PICKUP = 'ready_for_pickup';

    public const ASSIGNED_TO_RIDER = 'assigned_to_rider';

    public const PICKED_UP = 'picked_up';

    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    public const PAYMENT_FAILED = 'payment_failed';

    /**
     * from => [ to => [permitted actor roles] ]. A `system` actor is the
     * platform itself (payment capture, dispatch coupling).
     *
     * @var array<string, array<string, list<string>>>
     */
    private const EDGES = [
        self::PENDING_PAYMENT => [
            self::CONFIRMED => ['system'],
            self::PAYMENT_FAILED => ['system'],
            self::CANCELLED => ['customer', 'system'],
        ],
        self::CONFIRMED => [
            self::PREPARING => ['restaurant_operator', 'restaurant_staff'],
            self::REJECTED => ['restaurant_operator', 'restaurant_staff'],
            self::CANCELLED => ['customer'],
        ],
        self::PREPARING => [
            self::READY_FOR_PICKUP => ['restaurant_operator', 'restaurant_staff'],
        ],
        self::READY_FOR_PICKUP => [
            self::ASSIGNED_TO_RIDER => ['system'],
        ],
        self::ASSIGNED_TO_RIDER => [
            self::PICKED_UP => ['courier'],
            self::READY_FOR_PICKUP => ['system'],
        ],
        self::PICKED_UP => [
            self::OUT_FOR_DELIVERY => ['courier'],
        ],
        self::OUT_FOR_DELIVERY => [
            self::DELIVERED => ['courier'],
            self::FAILED => ['courier', 'system'],
        ],
    ];

    /**
     * The terminal states no edge leaves.
     *
     * @var list<string>
     */
    private const TERMINAL = [
        self::DELIVERED,
        self::CANCELLED,
        self::REJECTED,
        self::FAILED,
        self::PAYMENT_FAILED,
    ];

    public function isEdge(string $from, string $to): bool
    {
        return isset(self::EDGES[$from][$to]);
    }

    /**
     * @return list<string> the actor roles permitted to drive this edge
     */
    public function permittedActors(string $from, string $to): array
    {
        return self::EDGES[$from][$to] ?? [];
    }

    public function actorPermitted(string $from, string $to, string $actorRole): bool
    {
        return in_array($actorRole, self::permittedActors($from, $to), true);
    }

    public function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    /**
     * @return list<string>
     */
    public function states(): array
    {
        return [
            self::PENDING_PAYMENT,
            self::CONFIRMED,
            self::PREPARING,
            self::READY_FOR_PICKUP,
            self::ASSIGNED_TO_RIDER,
            self::PICKED_UP,
            self::OUT_FOR_DELIVERY,
            self::DELIVERED,
            self::CANCELLED,
            self::REJECTED,
            self::FAILED,
            self::PAYMENT_FAILED,
        ];
    }
}
