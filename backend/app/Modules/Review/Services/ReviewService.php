<?php

declare(strict_types=1);

namespace App\Modules\Review\Services;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Models\Restaurant;
use App\Modules\Order\Models\Order;
use App\Modules\Review\Models\Review;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ConflictException;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reviews and Aggregate_Rating (Requirement 17). A Customer may review a
 * delivered Order of their own exactly once; the Restaurant's rating aggregate
 * is recomputed in the same transaction as the write.
 */
final class ReviewService
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @return array<string, mixed>
     */
    public function submit(ActorContext $actor, string $orderId, int $rating, ?string $comment): array
    {
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::forField('rating', 'between:1,5');
        }

        /** @var Order|null $order */
        $order = Order::query()->find($orderId);

        if ($order === null || $order->customer_id !== $actor->userId) {
            throw AuthorizationException::notFound('order_not_found', 'No Order matches that identifier.');
        }

        if ($order->status !== 'delivered') {
            throw ValidationException::because('order_not_reviewable', 'Only a delivered Order can be reviewed.');
        }

        if (Review::query()->where('order_id', $orderId)->exists()) {
            throw ConflictException::because('review_already_exists', 'This Order has already been reviewed.');
        }

        $now = $this->clock->nowUtc();

        return DB::transaction(function () use ($order, $actor, $rating, $comment, $now): array {
            /** @var Review $review */
            $review = Review::query()->create([
                'id' => (string) Str::ulid(),
                'order_id' => $order->id,
                'customer_id' => $actor->userId,
                'restaurant_id' => $order->restaurant_id,
                'rating' => $rating,
                'comment' => $comment,
                'is_hidden' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->recomputeAggregate($order->restaurant_id, $now);

            return [
                'id' => $review->id,
                'order_id' => $review->order_id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'created_at' => $review->created_at,
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forRestaurant(string $restaurantId): array
    {
        return Review::query()
            ->where('restaurant_id', $restaurantId)
            ->where('is_hidden', false)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (Review $r): array => [
                'id' => $r->id,
                'rating' => $r->rating,
                'comment' => $r->comment,
                'created_at' => $r->created_at,
            ])->all();
    }

    private function recomputeAggregate(string $restaurantId, \DateTimeImmutable $now): void
    {
        $stats = Review::query()
            ->where('restaurant_id', $restaurantId)
            ->where('is_hidden', false)
            ->selectRaw('count(*) as total, avg(rating) as average')
            ->first();

        $total = (int) ($stats->total ?? 0);
        $average = $total > 0 ? round((float) $stats->average, 1) : null;

        DB::table('restaurant_rating_aggregates')->updateOrInsert(
            ['restaurant_id' => $restaurantId],
            ['total_reviews' => $total, 'average_rating' => $average, 'last_updated_at' => $now],
        );

        // Reflect the aggregate on the Restaurant row the discovery read uses.
        Restaurant::query()->where('id', $restaurantId)->update(['rating' => $average]);
    }
}
