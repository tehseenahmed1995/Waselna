<?php

declare(strict_types=1);

namespace App\Modules\Tracking\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast when an assigned Courier reports a new location, so the Customer and
 * Restaurant watching an Order get the moving pin in real time instead of
 * polling.
 *
 * Carried on a PRIVATE per-order channel. Subscribers are authorized by the
 * bearer-token broadcasting auth endpoint, which admits only the Order's
 * Customer, the Restaurant's operator/staff, and an Administrator.
 */
final class CourierLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $orderId,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $orderStatus,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('order.'.$this->orderId);
    }

    public function broadcastAs(): string
    {
        return 'courier.location';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'order_status' => $this->orderStatus,
        ];
    }
}
