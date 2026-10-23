<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Services;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Models\Restaurant;
use App\Modules\Catalog\Models\RestaurantBranch;
use App\Modules\Dispatch\Models\DeliveryJob;
use App\Modules\Order\Domain\OrderStateMachine;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderTransitionService;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ConflictException;
use App\Shared\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Delivery_Job lifecycle (Requirements 13, 14), reduced to the demo path:
 *
 *  - A job is created `unassigned` when an Order reaches `ready_for_pickup`.
 *  - Online Couriers see unassigned jobs and accept one. Acceptance is a
 *    conditional UPDATE so exactly one Courier wins; the Order moves to
 *    `assigned_to_rider`.
 *  - The assigned Courier drives pickup → in_transit → delivered, each coupling
 *    the Order to picked_up / out_for_delivery / delivered.
 */
final class DispatchService
{
    public function __construct(
        private readonly OrderTransitionService $transitions,
        private readonly Clock $clock,
    ) {}

    /**
     * Create a Delivery_Job for an Order that just became ready. Idempotent: a
     * job already exists for the Order is returned unchanged.
     */
    public function createForOrder(Order $order): DeliveryJob
    {
        /** @var DeliveryJob|null $existing */
        $existing = DeliveryJob::query()->where('order_id', $order->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $branch = RestaurantBranch::query()->find($order->restaurant_branch_id);
        $now = $this->clock->nowUtc();

        /** @var DeliveryJob $job */
        $job = DeliveryJob::query()->create([
            'id' => (string) Str::ulid(),
            'order_id' => $order->id,
            'status' => 'unassigned',
            'pickup_location' => [
                'latitude' => $branch?->latitude ?? 24.7136,
                'longitude' => $branch?->longitude ?? 46.6753,
            ],
            'dropoff_location' => [
                'latitude' => 24.7200,
                'longitude' => 46.6800,
            ],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $job;
    }

    /**
     * Unassigned jobs available for any online Courier to accept.
     *
     * @return list<array<string, mixed>>
     */
    public function availableJobs(ActorContext $actor): array
    {
        $this->assertCourier($actor);

        return DeliveryJob::query()
            ->where('status', 'unassigned')
            ->orderBy('created_at')
            ->get()
            ->map(fn (DeliveryJob $job): array => $this->summary($job))
            ->all();
    }

    /**
     * The Courier's currently assigned (active) job, or null.
     *
     * @return array<string, mixed>|null
     */
    public function activeJob(ActorContext $actor): ?array
    {
        $this->assertCourier($actor);

        /** @var DeliveryJob|null $job */
        $job = DeliveryJob::query()
            ->where('assigned_courier_id', $actor->userId)
            ->whereIn('status', ['accepted', 'pickup', 'picked_up', 'in_transit'])
            ->first();

        return $job === null ? null : $this->summary($job);
    }

    /**
     * The Courier's delivery history with optional filters, newest first, plus
     * aggregate totals. Scoped to the acting Courier's own jobs.
     *
     * @param  array<string, mixed>  $filters  status, from (Y-m-d), to (Y-m-d)
     * @return array{jobs: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function history(ActorContext $actor, array $filters = []): array
    {
        $this->assertCourier($actor);

        $query = DeliveryJob::query()->where('assigned_courier_id', $actor->userId);

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', (string) $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', (string) $filters['to']);
        }

        $jobs = $query->orderByDesc('created_at')->limit(500)->get();

        $delivered = 0;
        $earnings = 0;
        $currency = 'USD';
        $summaries = [];
        foreach ($jobs as $job) {
            $summary = $this->summary($job);
            $summaries[] = $summary;
            if ($job->status === 'delivered') {
                $delivered++;
                if ($summary['order_total'] !== null) {
                    $earnings += (int) $summary['order_total']['amount'];
                    $currency = (string) $summary['order_total']['currency'];
                }
            }
        }

        return [
            'jobs' => $summaries,
            'totals' => [
                'count' => $jobs->count(),
                'delivered' => $delivered,
                // Sum of delivered order totals (a stand-in for courier earnings
                // until a payout model exists).
                'delivered_value' => ['amount' => $earnings, 'currency' => $currency],
            ],
        ];
    }

    /**
     * Accept a job. A conditional UPDATE resolves exactly one winner; losers get
     * `job_already_assigned`. The Order moves to `assigned_to_rider`.
     *
     * @return array<string, mixed>
     */
    public function accept(ActorContext $actor, string $jobId): array
    {
        $this->assertCourier($actor);

        // One active job per Courier.
        $hasActive = DeliveryJob::query()
            ->where('assigned_courier_id', $actor->userId)
            ->whereIn('status', ['accepted', 'pickup', 'picked_up', 'in_transit'])
            ->exists();

        if ($hasActive) {
            throw ConflictException::because('courier_has_active_job', 'Finish your current delivery first.');
        }

        $now = $this->clock->nowUtc();

        $job = DB::transaction(function () use ($jobId, $actor, $now): DeliveryJob {
            // Conditional claim: only succeeds while still unassigned.
            $claimed = DeliveryJob::query()
                ->where('id', $jobId)
                ->where('status', 'unassigned')
                ->whereNull('assigned_courier_id')
                ->update([
                    'assigned_courier_id' => $actor->userId,
                    'status' => 'accepted',
                    'assigned_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($claimed === 0) {
                throw ConflictException::because('job_already_assigned', 'This delivery was already taken.');
            }

            /** @var DeliveryJob $job */
            $job = DeliveryJob::query()->findOrFail($jobId);

            $this->transitions->transition(
                $job->order_id,
                OrderStateMachine::ASSIGNED_TO_RIDER,
                'system',
                $actor->userId,
                'Courier assigned.',
            );

            return $job;
        });

        return $this->summary($job);
    }

    /**
     * @return array<string, mixed>
     */
    public function markPickedUp(ActorContext $actor, string $jobId): array
    {
        $job = $this->assignedJob($actor, $jobId);
        $now = $this->clock->nowUtc();

        $job->status = 'picked_up';
        $job->picked_up_at = $now;
        $job->updated_at = $now;
        $job->save();

        $this->transitions->transition($job->order_id, OrderStateMachine::PICKED_UP, 'courier', $actor->userId, 'Picked up.');

        return $this->summary($job);
    }

    /**
     * @return array<string, mixed>
     */
    public function markInTransit(ActorContext $actor, string $jobId): array
    {
        $job = $this->assignedJob($actor, $jobId);
        $job->status = 'in_transit';
        $job->updated_at = $this->clock->nowUtc();
        $job->save();

        $this->transitions->transition($job->order_id, OrderStateMachine::OUT_FOR_DELIVERY, 'courier', $actor->userId, 'Out for delivery.');

        return $this->summary($job);
    }

    /**
     * @return array<string, mixed>
     */
    public function markDelivered(ActorContext $actor, string $jobId): array
    {
        $job = $this->assignedJob($actor, $jobId);
        $now = $this->clock->nowUtc();

        $job->status = 'delivered';
        $job->delivered_at = $now;
        $job->updated_at = $now;
        $job->save();

        $this->transitions->transition($job->order_id, OrderStateMachine::DELIVERED, 'courier', $actor->userId, 'Delivered.');

        return $this->summary($job);
    }

    private function assignedJob(ActorContext $actor, string $jobId): DeliveryJob
    {
        $this->assertCourier($actor);

        /** @var DeliveryJob|null $job */
        $job = DeliveryJob::query()
            ->where('id', $jobId)
            ->where('assigned_courier_id', $actor->userId)
            ->first();

        if ($job === null) {
            throw AuthorizationException::notFound('delivery_not_found', 'No Delivery_Job matches that identifier.');
        }

        return $job;
    }

    private function assertCourier(ActorContext $actor): void
    {
        if (! $actor->hasRole('courier')) {
            throw AuthorizationException::forbidden();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(DeliveryJob $job): array
    {
        /** @var Order|null $order */
        $order = Order::query()->with('lines')->find($job->order_id);

        $restaurantName = null;
        $itemCount = 0;
        if ($order !== null) {
            /** @var Restaurant|null $restaurant */
            $restaurant = Restaurant::query()->find($order->restaurant_id);
            $restaurantName = $restaurant?->name;
            $itemCount = (int) $order->lines->sum('quantity');
        }

        return [
            'id' => $job->id,
            'order_id' => $job->order_id,
            'order_number' => $order?->order_number,
            'status' => $job->status,
            'order_status' => $order?->status,
            'order_total' => $order === null ? null : ['amount' => $order->total_amount_minor, 'currency' => $order->currency],
            'item_count' => $itemCount,
            'restaurant_name' => $restaurantName,
            'delivery_address' => $order?->delivery_address_snapshot,
            'pickup_location' => $job->pickup_location,
            'dropoff_location' => $job->dropoff_location,
            'delivered_at' => $job->delivered_at,
            'created_at' => $job->created_at,
        ];
    }
}
