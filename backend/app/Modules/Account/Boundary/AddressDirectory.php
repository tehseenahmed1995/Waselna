<?php

declare(strict_types=1);

namespace App\Modules\Account\Boundary;

use App\Modules\Account\Domain\AddressSnapshot;

/**
 * The Module_Boundary the Account module exposes for the Delivery_Address reads
 * that the Order and Pricing modules need but do not own (design Account notes;
 * Requirement 22.8).
 *
 * At checkout the Order module must copy a Customer's chosen Delivery_Address
 * into the immutable snapshot it stores on the Order (Requirement 8.7), and the
 * Pricing module must read the resolved coordinate to compute the distance-based
 * Delivery_Fee (Requirement 7.2). Both reach the address through this boundary
 * rather than through the Account module's `delivery_addresses` table, so the
 * encrypted contact detail is decrypted once, inside the owning module, and no
 * consumer gains a direct Eloquent dependency on an Account table.
 *
 * The read is scoped to the owning Customer and to non-archived addresses, so a
 * consumer can never resolve another Customer's address nor an address the
 * Customer has deleted (Requirements 31.10, 31.11).
 */
interface AddressDirectory
{
    /**
     * The decrypted snapshot of a non-archived Delivery_Address owned by
     * $userId, or null where $addressId matches none the Customer owns.
     */
    public function findOwnedAddress(string $userId, string $addressId): ?AddressSnapshot;
}
