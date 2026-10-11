<?php

declare(strict_types=1);

namespace App\Modules\Order\Services;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderLine;
use App\Shared\Domain\Exceptions\AuthorizationException;

/**
 * Role-scoped Order reads (Requirement 2.4–2.9, 19.1). A read that would name a
 * record belonging to another Platform user answers with the non-disclosing
 * `order_not_found` so absence and non-ownership are indistinguishable.
 */
final class OrderQueryService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listForCustomer(string $customerId): array
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->with('lines')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Order $order): array => $this->present($order))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function viewForActor(ActorContext $actor, string $orderId): array
    {
        /** @var Order|null $order */
        $order = Order::query()->with(['lines', 'timeline'])->find($orderId);

        if ($order === null || ! $this->canRead($actor, $order)) {
            throw AuthorizationException::notFound('order_not_found', 'No Order matches that identifier.');
        }

        return $this->present($order, withTimeline: true);
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

    /**
     * @return array<string, mixed>
     */
    private function present(Order $order, bool $withTimeline = false): array
    {
        $data = [
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
            'payment' => $this->paymentFor($order),
        ];

        if ($withTimeline) {
            $data['timeline'] = $order->timeline->map(fn ($entry): array => [
                'status' => $entry->new_status,
                'actor_role' => $entry->actor_role,
                'notes' => $entry->notes,
                'at' => $entry->created_at,
            ])->all();
        }

        return $data;
    }

    /**
     * The Payment_Intent summary for an Order, or a `none` placeholder when no
     * intent exists yet. Read through the Payment model directly here; a fuller
     * build would expose this through a Payment Module_Boundary reader.
     *
     * @return array{status: string, amount: array{amount: int, currency: string}, transaction_id: ?string}
     */
    private function paymentFor(Order $order): array
    {
        /** @var \App\Modules\Payment\Models\PaymentIntent|null $intent */
        $intent = \App\Modules\Payment\Models\PaymentIntent::query()
            ->where('order_id', $order->id)
            ->latest('created_at')
            ->first();

        if ($intent === null) {
            return [
                'status' => 'none',
                'amount' => ['amount' => $order->total_amount_minor, 'currency' => $order->currency],
                'transaction_id' => null,
            ];
        }

        return [
            'status' => $intent->status,
            'amount' => ['amount' => $intent->amount_minor, 'currency' => $intent->currency],
            'transaction_id' => $intent->gateway_transaction_id,
        ];
    }
}
