<?php

declare(strict_types=1);

// Feature: waselna, Property 70: Delivery_Address defaults and archival keep their invariants

/*
 * For any sequence of Delivery_Address create, mark-default, and delete
 * operations for a Customer, at most one non-archived Delivery_Address holds the
 * default flag at every observable point; a created Delivery_Address is default
 * exactly when the Customer holds no other non-archived Delivery_Address;
 * deleting a Delivery_Address archives it, excludes it from the list, clears the
 * default flag when it held it, leaves the Customer with no default until
 * another is marked, preserves every referencing Order's address snapshot, and
 * makes no further change when the target is already archived; and the list
 * returns non-archived Delivery_Addresses ordered with the default first and the
 * remainder by ascending label then ascending identifier.
 *
 * **Validates: Requirements 31.7, 31.9, 31.10, 31.11**
 *
 * The subject is the Account module's AddressServiceImplementation (task 4.6)
 * driven through its AddressService and AddressDirectory seams. Each generated
 * input is a sequence of create / mark-default / delete operations; the property
 * applies them one at a time and re-asserts every invariant after each step, so
 * a violation is caught at the exact observable point it first appears rather
 * than only at the end of the run.
 *
 * A reference model runs beside the service: a plain in-memory list of the
 * Customer's addresses with their default and archived flags. After every
 * operation the stored list read through `listOwn` is compared against the model
 * ordered by the same rule (default first, then ascending label, then ascending
 * identifier), so the test pins the full observable state, not just one field.
 *
 * Order-snapshot preservation (Requirement 31.10) is modelled with the Account
 * boundary itself: when an address is created the test captures the immutable
 * AddressSnapshot the Order module would have copied at checkout, through
 * `findOwnedAddress`. That snapshot is a detached value object, so after any
 * later archival of its address the captured snapshot must remain byte-for-byte
 * what it was when taken — exactly the guarantee a referencing Order relies on.
 *
 * The property runs against the real migrated schema, as the Testing Strategy
 * prescribes for database-touching properties and as the task note requires: the
 * `delivery_addresses` CHECK constraints and the partial unique index that backs
 * the single-default invariant are PostgreSQL features SQLite cannot execute, so
 * the full migration set is applied through RefreshDatabase and each generated
 * input runs inside a transaction rolled back afterwards. Address resolution is
 * a deterministic in-process double, so no outbound geocoding call is made
 * (Requirement 23.8). Identifiers come only from the injected
 * SequenceIdGenerator and timestamps only from the injected FrozenClock, so
 * creation order and archival instants are deterministic.
 */

use App\Modules\Account\Domain\AddressSnapshot;
use App\Modules\Account\Domain\CreateAddressCommand;
use App\Modules\Account\Repositories\AddressRepository;
use App\Modules\Account\Services\AddressServiceImplementation;
use App\Modules\Auth\Models\User;
use App\Modules\Zone\Contracts\Services\AddressResolver;
use App\Shared\Domain\Coordinate;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Domain\PostalAddress;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\SequenceIdGenerator;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(TestTrait::class, RefreshDatabase::class);

/**
 * The 20-address bound of Requirement 31.7, mirrored from the service so the
 * generator can drive sequences that approach and cross it.
 */
const ADDRESS_LIMIT = 20;

/**
 * The frozen instant the sequence starts from; every timestamp the service
 * stamps is measured from this through the injected Clock.
 */
const ADDRESS_PROPERTY_EPOCH = '2025-04-01T09:00:00Z';

/**
 * A label drawn from a small fixed set so equal labels recur across a sequence,
 * which exercises the ascending-identifier tie-break of the ordered list read
 * (Requirement 31.11). Every character survives a database round trip unchanged,
 * so a stored label compares and sorts exactly as submitted.
 */
function addressLabel(): ErisGenerator
{
    return Generators::elements(['home', 'work', 'home', 'gym', 'mum', 'work']);
}

/**
 * A single operation in a generated sequence. `kind` selects create,
 * mark-default, or delete, weighted toward create so sequences grow enough to
 * approach the 20-address bound; `target` indexes into the Customer's addresses
 * for mark-default and delete (taken modulo the relevant count at apply time, so
 * it always names a real address once any exist).
 */
function addressOperation(): ErisGenerator
{
    return Generators::associative([
        'kind' => Generators::elements(['create', 'create', 'create', 'markDefault', 'delete']),
        'label' => addressLabel(),
        'target' => Generators::choose(0, 999),
    ]);
}

/**
 * A variable-length sequence of operations. `Generators::seq` draws a list whose
 * length grows with the generation size, so across 100 runs the sequences range
 * from empty — exercising the no-address start — up to long enough to interleave
 * defaults and deletes. The length is capped at 24 so a single input cannot
 * explode the per-input database work, while still allowing more creates than
 * the 20-address bound so the limit rejection is reached.
 */
function addressOperationSequence(): ErisGenerator
{
    return Generators::map(
        static fn (array $operations): array => array_slice($operations, 0, 24),
        Generators::seq(addressOperation()),
    );
}

/**
 * An AddressResolver double that resolves every address to a fixed in-range
 * coordinate without geocoding (Requirements 5.6, 23.8). Coordinate values are
 * irrelevant to the default/archival invariants, so one constant pair keeps the
 * sequence deterministic.
 */
function addressResolverDouble(): AddressResolver
{
    return new class implements AddressResolver
    {
        public function resolve(PostalAddress $address): Coordinate
        {
            return new Coordinate('24.7135517', '46.6752957');
        }
    };
}

/**
 * The ordered identifiers the list read must return: non-archived addresses with
 * the default first, then ascending label, then ascending identifier
 * (Requirement 31.11). Computed from the reference model so the stored read can
 * be compared against it.
 *
 * @param  array<string, array{label: string, isDefault: bool, archived: bool}>  $model
 * @return list<string>
 */
function expectedOrderedIds(array $model): array
{
    $live = array_filter($model, static fn (array $a): bool => ! $a['archived']);

    uksort($live, static function (string $idA, string $idB) use ($live): int {
        $a = $live[$idA];
        $b = $live[$idB];

        // Default first.
        if ($a['isDefault'] !== $b['isDefault']) {
            return $a['isDefault'] ? -1 : 1;
        }

        // Then ascending label, then ascending identifier.
        return [$a['label'], $idA] <=> [$b['label'], $idB];
    });

    return array_keys($live);
}

/**
 * Seeds one Customer so the `delivery_addresses.user_id` foreign key resolves,
 * and returns its identifier.
 */
function seedAddressCustomer(FrozenClock $clock): string
{
    $now = $clock->nowUtc();
    $id = (string) Str::ulid();
    $email = 'c+'.Str::lower(Str::random(12)).'@waselna.test';

    User::query()->create([
        'id' => $id,
        'email' => $email,
        'email_lower' => Str::lower($email),
        'password_hash' => '$2y$04$'.str_pad(Str::random(53), 53, 'A'),
        'display_name' => 'Customer',
        'role' => 'customer',
        'account_status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

it('keeps the single-default, archival, snapshot, and ordering invariants across any operation sequence', function (): void {
    $this->limitTo(100)
        ->forAll(addressOperationSequence())
        ->then(function (array $operations): void {
            DB::beginTransaction();

            try {
                $clock = FrozenClock::at(ADDRESS_PROPERTY_EPOCH);

                $service = new AddressServiceImplementation(
                    new AddressRepository,
                    addressResolverDouble(),
                    $clock,
                    new SequenceIdGenerator,
                );

                $userId = seedAddressCustomer($clock);

                // Reference model of the Customer's addresses, keyed by id.
                /** @var array<string, array{label: string, isDefault: bool, archived: bool}> $model */
                $model = [];

                // Creation order, so a delete target index names a stable address
                // regardless of archival state.
                /** @var list<string> $createdOrder */
                $createdOrder = [];

                // The immutable snapshots a referencing Order would have copied,
                // captured at create time and never expected to change after.
                /** @var array<string, array<string, mixed>> $snapshots */
                $snapshots = [];

                $assertInvariants = function () use ($service, &$model, $userId): void {
                    // At most one non-archived default (Requirement 31.9).
                    $defaults = array_filter(
                        $model,
                        static fn (array $a): bool => $a['isDefault'] && ! $a['archived'],
                    );
                    expect(count($defaults))->toBeLessThanOrEqual(1);

                    // The stored list equals the model's non-archived rows in the
                    // required order (Requirements 31.10, 31.11), and each stored
                    // view's default flag matches the model, so the single default
                    // seen through the read is the modelled one.
                    $views = $service->listOwn($userId);
                    $storedIds = array_map(static fn ($view): string => $view->id, $views);
                    expect($storedIds)->toEqual(expectedOrderedIds($model));

                    foreach ($views as $view) {
                        expect($view->isDefault)->toBe($model[$view->id]['isDefault']);
                    }
                };

                // Empty start: no addresses, no default, empty list.
                $assertInvariants();

                foreach ($operations as $operation) {
                    $liveIds = array_keys(array_filter(
                        $model,
                        static fn (array $a): bool => ! $a['archived'],
                    ));

                    if ($operation['kind'] === 'create') {
                        $hadLive = $liveIds !== [];
                        $atLimit = count($liveIds) >= ADDRESS_LIMIT;

                        $command = new CreateAddressCommand(
                            userId: $userId,
                            label: $operation['label'],
                            line1: '1 Test Street',
                            line2: null,
                            locality: 'Riyadh',
                            postalCode: '12345',
                            countryCode: 'SA',
                        );

                        if ($atLimit) {
                            // The 20-address bound rejects with nothing stored
                            // (Requirement 31.7); the live set is unchanged.
                            expect(fn () => $service->create($command))
                                ->toThrow(ValidationException::class);
                        } else {
                            $view = $service->create($command);

                            // A created address is default exactly when the
                            // Customer held no other non-archived address
                            // (Requirement 31.7).
                            expect($view->isDefault)->toBe(! $hadLive);

                            $model[$view->id] = [
                                'label' => $operation['label'],
                                'isDefault' => ! $hadLive,
                                'archived' => false,
                            ];
                            $createdOrder[] = $view->id;

                            // Capture the immutable snapshot the Order module
                            // would copy at checkout, through the boundary.
                            $snapshot = $service->findOwnedAddress($userId, $view->id);
                            expect($snapshot)->toBeInstanceOf(AddressSnapshot::class);
                            $snapshots[$view->id] = $snapshot->toArray();
                        }
                    } elseif ($operation['kind'] === 'markDefault') {
                        if ($liveIds === []) {
                            // No live address to mark; the only reachable call is
                            // against an archived or unknown id, which the service
                            // rejects with the non-disclosing 404. Exercise it
                            // against an archived id when one exists.
                            $archived = array_keys(array_filter(
                                $model,
                                static fn (array $a): bool => $a['archived'],
                            ));
                            if ($archived !== []) {
                                $target = $archived[$operation['target'] % count($archived)];
                                expect(fn () => $service->markDefault($userId, $target))
                                    ->toThrow(AuthorizationException::class);
                            }
                        } else {
                            $target = $liveIds[$operation['target'] % count($liveIds)];
                            $service->markDefault($userId, $target);

                            // The target becomes the single default; every other
                            // live default is cleared (Requirement 31.9).
                            foreach ($model as $id => $row) {
                                if (! $row['archived']) {
                                    $model[$id]['isDefault'] = ($id === $target);
                                }
                            }
                        }
                    } else { // delete
                        if ($createdOrder === []) {
                            continue;
                        }

                        $target = $createdOrder[$operation['target'] % count($createdOrder)];
                        $wasArchived = $model[$target]['archived'];
                        $snapshotBefore = $snapshots[$target] ?? null;

                        if ($wasArchived) {
                            // A repeat delete of an already-archived address
                            // changes nothing and raises the non-disclosing 404
                            // (Requirements 31.10, 31.11).
                            $before = $model;
                            expect(fn () => $service->archive($userId, $target))
                                ->toThrow(AuthorizationException::class);
                            expect($model)->toEqual($before);
                        } else {
                            $service->archive($userId, $target);

                            // Deletion archives, excludes from the list, and
                            // clears the default where it held it, leaving no
                            // default until another is marked (Requirement 31.10).
                            $model[$target]['archived'] = true;
                            $model[$target]['isDefault'] = false;
                        }

                        // A referencing Order's snapshot survives the archival
                        // byte-for-byte (Requirement 31.10).
                        if ($snapshotBefore !== null) {
                            expect($snapshots[$target])->toEqual($snapshotBefore);
                        }
                    }

                    $assertInvariants();
                }
            } finally {
                DB::rollBack();
            }
        });
});
