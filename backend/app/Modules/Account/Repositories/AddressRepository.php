<?php

declare(strict_types=1);

namespace App\Modules\Account\Repositories;

use App\Modules\Account\Models\DeliveryAddress;

/**
 * The one place that queries the `delivery_addresses` table (Requirements
 * 31.7–31.11).
 *
 * Concrete and Eloquent-backed, following the same repository deviation the
 * ProfileRepository documents: the AddressService holds the rules, this holds
 * the queries. Every read filters to a single owning Customer, so a query can
 * never return another Customer's address, which is what lets the service return
 * the non-disclosing `address_not_found` of Requirement 31.11 by owner scope
 * alone.
 */
final class AddressRepository
{
    /**
     * The Customer's single non-archived default Delivery_Address identifier, or
     * null (Requirement 31.1).
     */
    public function defaultAddressId(string $userId): ?string
    {
        /** @var DeliveryAddress|null $row */
        $row = DeliveryAddress::query()
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->where('is_default', true)
            ->first(['id']);

        return $row?->id;
    }

    /**
     * A non-archived Delivery_Address owned by the Customer, locked for the
     * duration of the surrounding transaction, or null where the identifier
     * matches none the Customer owns (Requirements 31.9, 31.10, 31.11).
     *
     * The row lock serialises concurrent default or archive operations on the
     * same address so the single-default invariant cannot be raced.
     */
    public function findOwnedForUpdate(string $userId, string $addressId): ?DeliveryAddress
    {
        /** @var DeliveryAddress|null $address */
        $address = DeliveryAddress::query()
            ->where('user_id', $userId)
            ->where('id', $addressId)
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->first();

        return $address;
    }

    /**
     * A non-archived Delivery_Address owned by the Customer, without a lock, or
     * null (Requirement 31.11). Used by the directory boundary read.
     */
    public function findOwned(string $userId, string $addressId): ?DeliveryAddress
    {
        /** @var DeliveryAddress|null $address */
        $address = DeliveryAddress::query()
            ->where('user_id', $userId)
            ->where('id', $addressId)
            ->whereNull('archived_at')
            ->first();

        return $address;
    }

    /**
     * The count of the Customer's non-archived Delivery_Addresses, which the
     * 20-address bound is checked against; archived rows count toward neither the
     * bound nor the returned list (Requirements 31.7, 31.8).
     */
    public function countActive(string $userId): int
    {
        return DeliveryAddress::query()
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->count();
    }

    /**
     * Whether the Customer holds any non-archived Delivery_Address, used to
     * decide whether a newly created address becomes the default
     * (Requirement 31.7).
     */
    public function hasAnyActive(string $userId): bool
    {
        return DeliveryAddress::query()
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->exists();
    }

    /**
     * The Customer's non-archived Delivery_Addresses ordered with the default
     * first, then by ascending label and ascending identifier
     * (Requirement 31.11).
     *
     * @return list<DeliveryAddress>
     */
    public function listActive(string $userId): array
    {
        return array_values(
            DeliveryAddress::query()
                ->where('user_id', $userId)
                ->whereNull('archived_at')
                ->orderByDesc('is_default')
                ->orderBy('label')
                ->orderBy('id')
                ->get()
                ->all(),
        );
    }

    /**
     * Clears the default flag of every non-archived Delivery_Address of the
     * Customer other than $exceptAddressId (Requirement 31.9). Run inside the
     * same transaction as setting the new default.
     */
    public function clearDefaultExcept(string $userId, string $exceptAddressId): void
    {
        DeliveryAddress::query()
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->where('id', '!=', $exceptAddressId)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * Persists a Delivery_Address, whether a new row or an update to an existing
     * one.
     */
    public function save(DeliveryAddress $address): void
    {
        $address->save();
    }
}
