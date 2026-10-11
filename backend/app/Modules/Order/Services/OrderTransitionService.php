<?php

declare(strict_types=1);

namespace App\Modules\Order\Services;

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
    ) {}

    public function transition(
        string $orderId,
        string $toState,
        string $actorRole,
        ?string $actorId,
        ?string $notes = null,
    ): Order {
        return DB::transaction(function () use ($orderId, $toState, $actorRole, $actorId, $notes): Order {
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
    }
}
