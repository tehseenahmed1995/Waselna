<?php

declare(strict_types=1);

namespace App\Modules\Account\Contracts\Services;

use App\Modules\Account\Domain\AddressView;
use App\Modules\Account\Domain\CreateAddressCommand;
use App\Modules\Account\Domain\UpdateAddressCommand;
use App\Modules\Zone\Domain\Exceptions\AddressNotResolvable;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ValidationException;

/**
 * The Account module's saved Delivery_Address Service_Interface (Requirements
 * 31.7–31.11).
 *
 * One seam over one implementation: the Controller and tests depend on this
 * interface, satisfying Requirement 38 without a second production
 * implementation. Coordinate resolution is delegated across the Zone module's
 * AddressResolver seam, so this service never geocodes an address itself
 * (Requirement 5.6).
 */
interface AddressService
{
    /**
     * Resolves the address coordinate through the Zone module and stores a new
     * Delivery_Address against the Customer (Requirement 31.7).
     *
     * The created address is the default exactly when the Customer holds no
     * other non-archived address; the Customer may hold at most 20 non-archived
     * addresses, beyond which creation is rejected with `address_limit_reached`
     * (Requirement 31.8).
     *
     * @throws AddressNotResolvable when the
     *                              address resolves to no coordinate (Requirement 5.7); nothing is
     *                              stored.
     * @throws ValidationException with
     *                             `address_limit_reached` when the Customer already holds 20
     *                             non-archived addresses (Requirement 31.8).
     */
    public function create(CreateAddressCommand $command): AddressView;

    /**
     * Updates an existing non-archived Delivery_Address of the Customer,
     * re-resolving the coordinate (Requirement 31.7, 31.11).
     *
     * @throws AuthorizationException with
     *                                `address_not_found` where the identifier matches no non-archived
     *                                address the Customer owns (Requirement 31.11).
     * @throws AddressNotResolvable when the
     *                              updated address resolves to no coordinate (Requirement 5.7).
     */
    public function update(UpdateAddressCommand $command): AddressView;

    /**
     * Marks a non-archived Delivery_Address of the Customer as the single
     * default within one committed operation, clearing every other default
     * (Requirement 31.9).
     *
     * @throws AuthorizationException with
     *                                `address_not_found` where the identifier matches no non-archived
     *                                address the Customer owns (Requirement 31.11).
     */
    public function markDefault(string $userId, string $addressId): void;

    /**
     * Archives a non-archived Delivery_Address of the Customer, clearing the
     * default flag where it held it (Requirement 31.10). An already-archived
     * address is left unchanged.
     *
     * @throws AuthorizationException with
     *                                `address_not_found` where the identifier matches no non-archived
     *                                address the Customer owns (Requirement 31.11).
     */
    public function archive(string $userId, string $addressId): void;

    /**
     * The Customer's non-archived Delivery_Addresses, default first then by
     * ascending label and identifier (Requirement 31.11).
     *
     * @return list<AddressView>
     */
    public function listOwn(string $userId): array;
}
