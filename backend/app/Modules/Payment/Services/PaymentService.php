<?php

declare(strict_types=1);

namespace App\Modules\Payment\Services;

use App\Modules\Order\Domain\OrderStateMachine;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderTransitionService;
use App\Modules\Payment\Models\PaymentIntent;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ConflictException;
use App\Shared\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Payment_Intent lifecycle (Requirement 9), reduced to the demo happy path:
 * a Customer confirms payment for a `pending_payment` Order, the simulated
 * gateway authorizes and captures, and the Order moves to `confirmed`. A declined
 * or failed capture moves the Order to `payment_failed`.
 *
 * Exactly one Payment_Intent exists per Order for the Order_Total; the gateway
 * Idempotency_Key is derived from the Order identifier alone, so a repeated
 * confirm is a no-op on an already-captured intent.
 */
final class PaymentService
{
    public function __construct(
        private readonly SimulatedPaymentGateway $gateway,
        private readonly OrderTransitionService $transitions,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function pay(string $customerId, string $orderId): array
    {
        /** @var Order|null $order */
        $order = Order::query()->find($orderId);

        if ($order === null || $order->customer_id !== $customerId) {
            throw AuthorizationException::notFound('order_not_found', 'No Order matches that identifier.');
        }

        if ($order->status !== OrderStateMachine::PENDING_PAYMENT) {
            throw ConflictException::because(
                'invalid_state_transition',
                'This Order is not awaiting payment.',
                ['status' => $order->status],
            );
        }

        $intent = $this->intentFor($order);

        if ($intent->status === 'captured') {
            return $this->present($order->fresh() ?? $order, $intent);
        }

        $result = $this->gateway->authorizeAndCapture(
            'order_'.$order->id,
            $order->total_amount_minor,
            $order->currency,
        );

        $now = $this->clock->nowUtc();

        if ($result['outcome'] === SimulatedPaymentGateway::OUTCOME_CAPTURED) {
            $intent->status = 'captured';
            $intent->gateway_transaction_id = $result['transaction_id'];
            $intent->updated_at = $now;
            $intent->save();

            $this->transitions->transition(
                $order->id,
                OrderStateMachine::CONFIRMED,
                'system',
                null,
                'Payment captured.',
            );
        } else {
            $intent->status = $result['outcome'] === SimulatedPaymentGateway::OUTCOME_DECLINED ? 'declined' : 'failed';
            $intent->updated_at = $now;
            $intent->save();

            $this->transitions->transition(
                $order->id,
                OrderStateMachine::PAYMENT_FAILED,
                'system',
                null,
                'Payment '.$intent->status.'.',
            );
        }

        return $this->present($order->fresh() ?? $order, $intent);
    }

    private function intentFor(Order $order): PaymentIntent
    {
        /** @var PaymentIntent|null $intent */
        $intent = PaymentIntent::query()->where('order_id', $order->id)->first();

        if ($intent !== null) {
            return $intent;
        }

        $now = $this->clock->nowUtc();

        /** @var PaymentIntent $created */
        $created = DB::transaction(fn (): PaymentIntent => PaymentIntent::query()->create([
            'id' => (string) Str::ulid(),
            'order_id' => $order->id,
            'amount_minor' => $order->total_amount_minor,
            'currency' => $order->currency,
            'status' => 'pending',
            'gateway' => 'simulated',
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        return $created;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Order $order, PaymentIntent $intent): array
    {
        return [
            'order_id' => $order->id,
            'order_status' => $order->status,
            'payment' => [
                'status' => $intent->status,
                'amount' => ['amount' => $intent->amount_minor, 'currency' => $intent->currency],
                'transaction_id' => $intent->gateway_transaction_id,
            ],
        ];
    }
}
