<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Catalog\Models\Restaurant;
use App\Modules\Dispatch\Models\DeliveryJob;
use App\Modules\Order\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Administrator read surfaces (Requirement 18, 37): platform-wide lists of
 * orders, restaurants, couriers, and delivery jobs, plus a summary dashboard.
 * Read-only; every list delegates to the owning module's model.
 */
final class AdminService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $ordersByStatus = Order::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $gross = (int) Order::query()
            ->whereNotIn('status', ['pending_payment', 'payment_failed', 'cancelled', 'rejected'])
            ->sum('total_amount_minor');

        // Today's window, in UTC, so the dashboard can show "today" alongside
        // the all-time figures (Requirement 37: Administrator oversight surface).
        $startOfToday = now()->utc()->startOfDay();

        $grossToday = (int) Order::query()
            ->where('created_at', '>=', $startOfToday)
            ->whereNotIn('status', ['pending_payment', 'payment_failed', 'cancelled', 'rejected'])
            ->sum('total_amount_minor');

        return [
            'orders_total' => Order::query()->count(),
            'orders_today' => Order::query()->where('created_at', '>=', $startOfToday)->count(),
            'orders_by_status' => $ordersByStatus,
            'gross_revenue' => ['amount' => $gross, 'currency' => 'USD'],
            'gross_revenue_today' => ['amount' => $grossToday, 'currency' => 'USD'],
            'restaurants' => Restaurant::query()->count(),
            'couriers' => User::query()->where('role', 'courier')->count(),
            'active_deliveries' => DeliveryJob::query()
                ->whereIn('status', ['accepted', 'pickup', 'picked_up', 'in_transit'])
                ->count(),
        ];
    }

    /**
     * @param  array{status?: string, restaurant_id?: string}  $filters
     * @return list<array<string, mixed>>
     */
    public function orders(array $filters = []): array
    {
        $query = Order::query()->with('lines')->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['restaurant_id'])) {
            $query->where('restaurant_id', $filters['restaurant_id']);
        }

        return array_values($query->limit(100)->get()->map(fn (Order $o): array => [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'status' => $o->status,
            'customer_id' => $o->customer_id,
            'restaurant_id' => $o->restaurant_id,
            'order_total' => ['amount' => $o->total_amount_minor, 'currency' => $o->currency],
            'line_count' => $o->lines->count(),
            'created_at' => $o->created_at,
        ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function restaurants(): array
    {
        return array_values(Restaurant::query()->withCount('items')->orderBy('name')->get()->map(fn (Restaurant $r): array => [
            'id' => $r->id,
            'name' => $r->name,
            'status' => $r->status,
            'currency' => $r->currency,
            'rating' => $r->rating,
            'menu_items' => $r->items_count,
        ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function couriers(): array
    {
        $rows = User::query()
            ->where('role', 'courier')
            ->leftJoin('courier_profiles', 'users.id', '=', 'courier_profiles.user_id')
            ->orderBy('users.display_name')
            ->get([
                'users.id',
                'users.display_name',
                'users.email',
                'users.account_status',
                'courier_profiles.rider_status',
                'courier_profiles.vehicle_type',
            ]);

        $out = [];
        foreach ($rows as $u) {
            $out[] = [
                'id' => $u->getAttribute('id'),
                'display_name' => $u->getAttribute('display_name'),
                'email' => $u->getAttribute('email'),
                'account_status' => $u->getAttribute('account_status'),
                'rider_status' => $u->getAttribute('rider_status') ?? 'offline',
                'vehicle_type' => $u->getAttribute('vehicle_type'),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deliveries(): array
    {
        return array_values(DeliveryJob::query()->orderByDesc('created_at')->limit(100)->get()->map(function (DeliveryJob $j): array {
            /** @var Order|null $order */
            $order = Order::query()->find($j->order_id);

            return [
                'id' => $j->id,
                'order_number' => $order?->order_number,
                'status' => $j->status,
                'assigned_courier_id' => $j->assigned_courier_id,
                'created_at' => $j->created_at,
            ];
        })->all());
    }
}
