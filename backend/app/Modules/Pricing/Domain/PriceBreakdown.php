<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Shared\Domain\Money;
use InvalidArgumentException;

/**
 * The immutable result of a Pricing_Engine quote (Requirement 7.8).
 *
 * Every component is an integer minor-unit {@see Money} amount in the Cart's
 * Restaurant currency, and the six amounts are returned together with the
 * ISO 4217 currency code. The {@see $provisional} flag is set when the quote was
 * produced without a selected Delivery_Address, in which case Delivery_Fee is
 * the Restaurant's configured minimum and every downstream value derives from
 * it (Requirement 7.12).
 *
 * The constructor asserts the Requirement 7.3 storable range on Order_Total and
 * that every component shares one currency, so an engine that composed the
 * breakdown incorrectly fails here rather than at the database check constraint.
 */
final readonly class PriceBreakdown
{
    public string $currency;

    public function __construct(
        public Money $lineSubtotal,
        public Money $deliveryFee,
        public Money $serviceFee,
        public Money $taxAmount,
        public Money $discountAmount,
        public Money $orderTotal,
        public bool $provisional,
    ) {
        $this->currency = $lineSubtotal->currency;

        foreach ([$deliveryFee, $serviceFee, $taxAmount, $discountAmount, $orderTotal] as $component) {
            if ($component->currency !== $this->currency) {
                throw new InvalidArgumentException(sprintf(
                    'A price breakdown mixes currencies %s and %s.',
                    $this->currency,
                    $component->currency,
                ));
            }
        }

        $orderTotal->assertWithinStorableRange();
    }

    /**
     * The Requirement 7.8 / 20.9 representation: each component as an integer
     * minor-unit amount with its currency, plus the provisional flag.
     *
     * @return array{
     *     currency: string,
     *     provisional: bool,
     *     line_subtotal: array{amount: int, currency: string},
     *     delivery_fee: array{amount: int, currency: string},
     *     service_fee: array{amount: int, currency: string},
     *     tax_amount: array{amount: int, currency: string},
     *     discount_amount: array{amount: int, currency: string},
     *     order_total: array{amount: int, currency: string}
     * }
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'provisional' => $this->provisional,
            'line_subtotal' => $this->lineSubtotal->toArray(),
            'delivery_fee' => $this->deliveryFee->toArray(),
            'service_fee' => $this->serviceFee->toArray(),
            'tax_amount' => $this->taxAmount->toArray(),
            'discount_amount' => $this->discountAmount->toArray(),
            'order_total' => $this->orderTotal->toArray(),
        ];
    }
}
