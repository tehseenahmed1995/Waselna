<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * The twelve Order_States (Requirement 10). These mirror the constants on
 * App\Modules\Order\Domain\OrderStateMachine, which remains the authority on the
 * permitted transition edges; this enum is the shared vocabulary for status
 * values and their display labels across the API and admin surfaces.
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case ReadyForPickup = 'ready_for_pickup';
    case AssignedToRider = 'assigned_to_rider';
    case PickedUp = 'picked_up';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case PaymentFailed = 'payment_failed';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::Confirmed => 'Confirmed',
            self::Preparing => 'Preparing',
            self::ReadyForPickup => 'Ready for pickup',
            self::AssignedToRider => 'Rider assigned',
            self::PickedUp => 'Picked up',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
            self::PaymentFailed => 'Payment failed',
        };
    }

    /**
     * The non-terminal statuses an active Order can be in.
     *
     * @return list<string>
     */
    public static function active(): array
    {
        return [
            self::Confirmed->value,
            self::Preparing->value,
            self::ReadyForPickup->value,
            self::AssignedToRider->value,
            self::PickedUp->value,
            self::OutForDelivery->value,
        ];
    }
}
