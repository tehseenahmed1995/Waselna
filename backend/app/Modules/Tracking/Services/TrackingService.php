<?php

declare(strict_types=1);

namespace App\Modules\Tracking\Services;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Dispatch\Models\DeliveryJob;
use App\Modules\Order\Models\Order;
use App\Modules\Tracking\Events\CourierLocationUpdated;
use App\Modules\Tracking\Models\CourierLocation;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Support\Clock;
use App\Shared\Support\IdGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Courier_Location ingestion and Order tracking reads (Requirement 15). A
 * Courier's device posts its current position; a Customer (their own Order), the
 * Restaurant (operator/staff of the Order's Restaurant), and an Administrator
 * may read the live position for an Order that has a Courier assigned.
 */
final class TrackingService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
    ) {}

    /**
     * Record the acting Courier's current location (upsert) and append a history
     * row.
     */
    public function recordLocation(ActorContext $actor, float $latitude, float $longitude): void
    {
        if (! $actor->hasRole('courier')) {
            throw AuthorizationException::forbidden();
        }

        $now = $this->clock->nowUtc();

        DB::transaction(function () use ($actor, $latitude, $longitude, $now): void {
            CourierLocation::query()->updateOrCreate(
                ['courier_id' => $actor->userId],
                ['id' => $this->ids->ulid(), 'latitude' => $latitude, 'longitude' => $longitude, 'updated_at' => $now],
            );

            DB::table('courier_location_history')->insert([
                'id' => $this->ids->ulid(),
                'courier_id' => $actor->userId,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'created_at' => $now,
            ]);
        });

        // Push the new position to anyone tracking the courier's active order.
        /** @var DeliveryJob|null $job */
        $job = DeliveryJob::query()
            ->where('assigned_courier_id', $actor->userId)
            ->whereIn('status', ['accepted', 'pickup', 'picked_up', 'in_transit'])
            ->first();

        if ($job !== null) {
            /** @var Order|null $order */
            $order = Order::query()->find($job->order_id);
            if ($order !== null) {
                CourierLocationUpdated::dispatch($order->id, $latitude, $longitude, (string) $order->status);
            }
        }

        // Push the new position to anyone tracking the courier's active order.
        /** @var DeliveryJob|null $job */
        $job = DeliveryJob::query()
            ->where('assigned_courier_id', $actor->userId)
            ->whereIn('status', ['accepted', 'pickup', 'picked_up', 'in_transit'])
            ->first();

        if ($job !== null) {
            /** @var Order|null $order */
            $order = Order::query()->find($job->order_id);
            if ($order !== null) {
                CourierLocationUpdated::dispatch($order->id, $latitude, $longitude, (string) $order->status);
            }
        }
    }

    /**
     * The live tracking view for an Order: pickup and dropoff coordinates, the
     * Order status, and the assigned Courier's latest location (null until a
     * Courier is assigned and has reported a position).
     *
     * @return array<string, mixed>
     */
    public function trackOrder(ActorContext $actor, string $orderId): array
    {
        /** @var Order|null $order */
        $order = Order::query()->find($orderId);

        if ($order === null || ! $this->canRead($actor, $order)) {
            throw AuthorizationException::notFound('order_not_found', 'No Order matches that identifier.');
        }

        /** @var DeliveryJob|null $job */
        $job = DeliveryJob::query()->where('order_id', $order->id)->first();

        $courierLocation = null;
        $courierId = $job?->assigned_courier_id;
        if ($courierId !== null) {
            /** @var CourierLocation|null $loc */
            $loc = CourierLocation::query()->where('courier_id', $courierId)->first();
            if ($loc !== null) {
                $courierLocation = [
                    'latitude' => (float) $loc->latitude,
                    'longitude' => (float) $loc->longitude,
                    'updated_at' => $loc->updated_at,
                ];
            }
        }

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'job_status' => $job?->status,
            'pickup' => $job?->pickup_location,
            'dropoff' => $this->dropoffFor($order, $job),
            'courier_location' => $courierLocation,
            'has_courier' => $courierId !== null,
        ];
    }

    /**
     * The dropoff coordinates: the job's stored dropoff, falling back to the
     * order's delivery-address snapshot if it carries coordinates.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    private function dropoffFor(Order $order, ?DeliveryJob $job): ?array
    {
        if (is_array($job?->dropoff_location) && isset($job->dropoff_location['latitude'], $job->dropoff_location['longitude'])) {
            return [
                'latitude' => (float) $job->dropoff_location['latitude'],
                'longitude' => (float) $job->dropoff_location['longitude'],
            ];
        }

        $snapshot = $order->delivery_address_snapshot;
        if (is_array($snapshot) && isset($snapshot['latitude'], $snapshot['longitude'])) {
            return [
                'latitude' => (float) $snapshot['latitude'],
                'longitude' => (float) $snapshot['longitude'],
            ];
        }

        return null;
    }

    private function canRead(ActorContext $actor, Order $order): bool
    {
        return match ($actor->role) {
            'customer' => $order->customer_id === $actor->userId,
            'restaurant_operator', 'restaurant_staff' => $order->restaurant_id === $actor->restaurantId,
            'administrator' => true,
            default => false,
        };
    }
}
