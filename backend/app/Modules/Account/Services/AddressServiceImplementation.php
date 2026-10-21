<?php

declare(strict_types=1);

namespace App\Modules\Account\Services;

use App\Modules\Account\Boundary\AddressDirectory;
use App\Modules\Account\Contracts\Services\AddressService;
use App\Modules\Account\Domain\AddressSnapshot;
use App\Modules\Account\Domain\AddressView;
use App\Modules\Account\Domain\CreateAddressCommand;
use App\Modules\Account\Domain\UpdateAddressCommand;
use App\Modules\Account\Models\DeliveryAddress;
use App\Modules\Account\Repositories\AddressRepository;
use App\Modules\Zone\Contracts\Services\AddressResolver;
use App\Shared\Domain\Coordinate;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\Clock;
use App\Shared\Support\IdGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Saved Delivery_Address CRUD, default-address handling, and archival
 * (Requirements 31.7–31.11), and the {@see AddressDirectory} boundary read the
 * Order and Pricing modules consume.
 *
 * The service holds the rules; the {@see AddressRepository} holds the queries.
 * Coordinate resolution is delegated across the Zone module's
 * {@see AddressResolver} seam, so this service never geocodes an address itself
 * (Requirement 5.6) and a no-match address is rejected before any write, leaving
 * nothing stored (Requirement 5.7). Identifiers come only from the injected
 * {@see IdGenerator} and timestamps only from the injected {@see Clock}, so
 * creation order and archival instants are deterministic under test.
 *
 * Every mutation that touches the single-default invariant runs inside one
 * database transaction and locks the Customer's addresses for update, so the
 * "at most one non-archived default per Customer" rule holds at every observable
 * point (Requirement 31.9, design Property 70); the partial unique index backs
 * the same rule at the database level.
 */
final class AddressServiceImplementation implements AddressDirectory, AddressService
{
    /**
     * The maximum number of non-archived Delivery_Addresses a Customer may hold
     * (Requirement 31.7). Archived addresses count toward neither this bound nor
     * the returned list (Requirement 31.8).
     */
    public const MAX_ACTIVE_ADDRESSES = 20;

    public function __construct(
        private readonly AddressRepository $addresses,
        private readonly AddressResolver $resolver,
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
    ) {}

    public function create(CreateAddressCommand $command): AddressView
    {
        // Resolve before opening the transaction: a no-match address is rejected
        // with nothing stored (Requirements 5.6, 5.7, 31.7).
        $coordinate = $this->resolver->resolve($command->toPostalAddress());

        return DB::transaction(function () use ($command, $coordinate): AddressView {
            // The 20-address bound is checked at the bound and nowhere else
            // (Requirement 31.8, design Property 23); archived rows do not count.
            if ($this->addresses->countActive($command->userId) >= self::MAX_ACTIVE_ADDRESSES) {
                throw ValidationException::because(
                    'address_limit_reached',
                    'A Customer may hold at most '.self::MAX_ACTIVE_ADDRESSES.' saved addresses.',
                );
            }

            // The first non-archived address of the Customer becomes the default;
            // any later one does not (Requirement 31.7).
            $isDefault = ! $this->addresses->hasAnyActive($command->userId);

            $now = $this->clock->nowUtc();

            // Timestamps are set through the mass-assignment array (the pattern
            // the EmailChangeToken write uses) so the injected Clock drives them
            // deterministically rather than Eloquent's wall-clock default.
            $address = new DeliveryAddress([
                'id' => $this->ids->ulid(),
                'user_id' => $command->userId,
                'label' => $command->label,
                'line1_encrypted' => $command->line1,
                'line2_encrypted' => $command->line2,
                'locality_encrypted' => $command->locality,
                'postal_code_encrypted' => $command->postalCode,
                'country_code' => $command->countryCode,
                'latitude' => $coordinate->latitude,
                'longitude' => $coordinate->longitude,
                'is_default' => $isDefault,
                'archived_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->addresses->save($address);

            return AddressView::fromModel($address);
        });
    }

    public function update(UpdateAddressCommand $command): AddressView
    {
        // Re-resolve before opening the transaction so an updated address that no
        // longer resolves is rejected with nothing changed (Requirement 5.7).
        $coordinate = $this->resolver->resolve($command->toPostalAddress());

        return DB::transaction(function () use ($command, $coordinate): AddressView {
            $address = $this->addresses->findOwnedForUpdate($command->userId, $command->addressId);

            if ($address === null) {
                throw $this->notFound($command->addressId);
            }

            $address->fill([
                'label' => $command->label,
                'line1_encrypted' => $command->line1,
                'line2_encrypted' => $command->line2,
                'locality_encrypted' => $command->locality,
                'postal_code_encrypted' => $command->postalCode,
                'country_code' => $command->countryCode,
                'latitude' => $coordinate->latitude,
                'longitude' => $coordinate->longitude,
                'updated_at' => $this->clock->nowUtc(),
            ]);

            $this->addresses->save($address);

            return AddressView::fromModel($address);
        });
    }

    public function markDefault(string $userId, string $addressId): void
    {
        DB::transaction(function () use ($userId, $addressId): void {
            $address = $this->addresses->findOwnedForUpdate($userId, $addressId);

            if ($address === null) {
                throw $this->notFound($addressId);
            }

            // Marking an address that already holds the default leaves every
            // address unchanged (Requirement 31.9).
            if ($address->is_default) {
                return;
            }

            // Clear every other default, then set this one, within the one
            // committed operation (Requirement 31.9).
            $this->addresses->clearDefaultExcept($userId, $addressId);

            $address->fill([
                'is_default' => true,
                'updated_at' => $this->clock->nowUtc(),
            ]);
            $this->addresses->save($address);
        });
    }

    public function archive(string $userId, string $addressId): void
    {
        DB::transaction(function () use ($userId, $addressId): void {
            $address = $this->addresses->findOwnedForUpdate($userId, $addressId);

            // An already-archived address matches no non-archived row, so the
            // lookup returns null and the non-disclosing 404 is raised; a repeat
            // delete therefore changes nothing (Requirements 31.10, 31.11).
            if ($address === null) {
                throw $this->notFound($addressId);
            }

            // Archive and clear the default where it held it, leaving the
            // Customer with no default until another is chosen (Requirement 31.10).
            $now = $this->clock->nowUtc();
            $address->fill([
                'archived_at' => $now,
                'is_default' => false,
                'updated_at' => $now,
            ]);
            $this->addresses->save($address);
        });
    }

    /**
     * @return list<AddressView>
     */
    public function listOwn(string $userId): array
    {
        return array_map(
            static fn (DeliveryAddress $a): AddressView => AddressView::fromModel($a),
            $this->addresses->listActive($userId),
        );
    }

    public function findOwnedAddress(string $userId, string $addressId): ?AddressSnapshot
    {
        $address = $this->addresses->findOwned($userId, $addressId);

        if ($address === null) {
            return null;
        }

        return new AddressSnapshot(
            addressId: $address->id,
            userId: $address->user_id,
            label: $address->label,
            line1: (string) $address->line1_encrypted,
            line2: $address->line2_encrypted,
            locality: (string) $address->locality_encrypted,
            postalCode: (string) $address->postal_code_encrypted,
            countryCode: $address->country_code,
            coordinate: new Coordinate((string) $address->latitude, (string) $address->longitude),
        );
    }

    /**
     * The non-disclosing 404 of Requirement 31.11: identical whether the address
     * does not exist or belongs to another Customer, so the response never
     * reveals another Customer's address.
     */
    private function notFound(string $addressId): AuthorizationException
    {
        return AuthorizationException::notFound(
            'address_not_found',
            'No matching Delivery_Address was found.',
            ['address_id' => $addressId],
        );
    }
}
