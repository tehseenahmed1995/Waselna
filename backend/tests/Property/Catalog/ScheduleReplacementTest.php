<?php

declare(strict_types=1);

// Feature: waselna, Property 31: Submitted schedules replace the stored schedule atomically

/*
 * For any submitted weekly schedule, an accepted submission replaces the
 * complete stored weekly schedule of that owner with exactly the submitted
 * intervals, and a submission violating any interval rule leaves the stored
 * schedule byte-unchanged with no interval of the submitted schedule applied.
 *
 * **Validates: Requirements 11.7, 11.8, 32.5, 32.6**
 *
 * The subject is the Catalog module's HoursManagementServiceImplementation
 * (task 7.4) driven through its HoursManagementService seam, paired with the
 * concrete HoursRepository that performs the delete-all-then-insert-all against
 * the `opening_hours` / `branch_hours` tables. The property exercises both
 * owners — a Restaurant's Opening_Hours and a Restaurant_Branch's Branch_Hours —
 * since the atomic-replacement contract is identical for each.
 *
 * Each generated input is a pair: an arbitrary already-stored schedule the owner
 * starts from, and a submitted schedule that is either fully valid or carries
 * exactly one injected interval-rule violation (overlap, more than three
 * intervals in a day, a minute outside the closed 0..1440 bounds, or a start at
 * or after its end). The property applies the submission and asserts the single
 * guarantee for its kind:
 *
 *  - *Accepted.* After a valid submission the stored rows are exactly the
 *    submitted intervals, grouped by day, with no interval of the prior stored
 *    schedule surviving (Requirement 11.7, 32.5, 32.6). A reference projection
 *    of the submitted schedule is compared byte-for-byte against the rows read
 *    back, so a partial write — some days replaced, others not — fails here.
 *  - *Rejected.* A submission breaking any interval rule raises `invalid_hours`
 *    and the stored rows are byte-for-byte what they were before the call, with
 *    no interval of the submitted schedule applied (Requirement 11.8, 32.6).
 *    The rejection is checked to carry the `invalid_hours` code, not merely to
 *    be a ValidationException, so a different validation failure would not pass.
 *
 * Database and determinism. The service rejects an interval-rule violation in
 * pure PHP before opening the replacement transaction, so the atomicity this
 * property is about is observable without the Postgres-only GIST `EXCLUDE`
 * overlap constraint; the database-level overlap bar is exercised separately in
 * the Integration suite (tests/Integration/Catalog/HoursExclusionConstraintTest).
 * The property therefore runs on the suite's SQLite in-memory connection
 * (phpunit.xml forces DB_CONNECTION=sqlite and DB_DATABASE=:memory:; a CLI run
 * against the pgsql container must set those explicitly because the container
 * .env defaults to pgsql). The two interval tables are built directly from the
 * task 7.4 migration's columns — matching the Catalog Feature suite's SQLite
 * approach — rather than by running the btree_gist-bearing migration set, which
 * SQLite cannot apply. Each generated input runs inside a transaction rolled
 * back afterwards, as the Testing Strategy prescribes for database-touching
 * properties. Identifiers come only from the injected SequenceIdGenerator, so
 * row creation is deterministic; the subject reads no wall clock, so no Clock
 * injection beyond the shared kernel's is required.
 *
 * All global helpers are prefixed `property31` so they never collide with the
 * same-named helpers of the Catalog Feature suite loaded in the same run.
 */

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Contracts\Services\HoursManagementService;
use App\Modules\Catalog\Domain\SubmittedSchedule;
use App\Modules\Catalog\Repositories\HoursRepository;
use App\Modules\Catalog\Services\HoursManagementServiceImplementation;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\SequenceIdGenerator;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(TestTrait::class);

/**
 * The seven permitted day-of-week names, in canonical week order.
 *
 * @var list<string>
 */
const PROPERTY31_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

/**
 * Builds the two interval tables directly from the task 7.4 migration's columns,
 * plus the owner tables their foreign keys reference. The Postgres-only CHECK
 * and GIST EXCLUDE constraints are deliberately omitted: this property is about
 * the service's own all-or-nothing replacement, which rejects a rule violation
 * before any write, so the portable columns are enough to observe it on SQLite.
 */
function property31CreateSchema(): void
{
    Schema::dropIfExists('branch_hours');
    Schema::dropIfExists('opening_hours');
    Schema::dropIfExists('restaurant_branches');
    Schema::dropIfExists('restaurants');

    Schema::create('restaurants', function ($table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('status', 20)->default('draft');
        $table->string('currency', 3)->default('USD');
        $table->string('time_zone', 64)->default('UTC');
    });

    Schema::create('restaurant_branches', function ($table): void {
        $table->string('id')->primary();
        $table->string('restaurant_id');
        $table->string('name');
        $table->string('time_zone', 64)->nullable();
        $table->boolean('is_active')->default(true);
    });

    foreach (['opening_hours' => 'restaurant_id', 'branch_hours' => 'restaurant_branch_id'] as $table => $owner) {
        Schema::create($table, function ($blueprint) use ($owner): void {
            $blueprint->string('id')->primary();
            $blueprint->string($owner);
            $blueprint->string('day_of_week', 9);
            $blueprint->integer('start_minute');
            $blueprint->integer('end_minute');
        });
    }
}

function property31Service(): HoursManagementService
{
    return new HoursManagementServiceImplementation(
        new HoursRepository(new SequenceIdGenerator),
    );
}

function property31Administrator(): ActorContext
{
    return new ActorContext((string) Str::ulid(), 'administrator', 'active');
}

function property31SeedRestaurant(): string
{
    $id = (string) Str::ulid();
    DB::table('restaurants')->insert([
        'id' => $id,
        'name' => 'Resto '.Str::random(5),
        'status' => 'draft',
        'currency' => 'USD',
        'time_zone' => 'UTC',
    ]);

    return $id;
}

function property31SeedBranch(string $restaurantId): string
{
    $id = (string) Str::ulid();
    DB::table('restaurant_branches')->insert([
        'id' => $id,
        'restaurant_id' => $restaurantId,
        'name' => 'Branch',
        'is_active' => true,
    ]);

    return $id;
}

/**
 * The stored rows of one owner, grouped by day into ordered [start, end] pairs,
 * so a submitted schedule and the rows read back can be compared independently
 * of insertion order and row identity.
 *
 * @return array<string, list<array{int, int}>>
 */
function property31StoredByDay(string $table, string $ownerColumn, string $ownerId): array
{
    $rows = DB::table($table)
        ->where($ownerColumn, $ownerId)
        ->orderBy('day_of_week')
        ->orderBy('start_minute')
        ->orderBy('end_minute')
        ->get();

    $byDay = [];
    foreach ($rows as $row) {
        $byDay[$row->day_of_week][] = [(int) $row->start_minute, (int) $row->end_minute];
    }

    return $byDay;
}

/**
 * The same grouping shape derived from a submitted schedule map, so an accepted
 * submission can be checked to have stored exactly its intervals. Days with no
 * interval contribute no key, matching how the repository stores nothing for a
 * closed day.
 *
 * @param  array<string, list<array{start_minute: int, end_minute: int}>>  $submitted
 * @return array<string, list<array{int, int}>>
 */
function property31ExpectedByDay(array $submitted): array
{
    $byDay = [];
    foreach ($submitted as $day => $intervals) {
        if ($intervals === []) {
            continue;
        }

        $pairs = array_map(
            static fn (array $interval): array => [$interval['start_minute'], $interval['end_minute']],
            $intervals,
        );

        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $byDay[$day] = $pairs;
    }

    ksort($byDay);

    return $byDay;
}

/**
 * One well-formed half-open interval `[start, end)` with both bounds inside the
 * closed 0..1440 range and start strictly before end.
 *
 * @return ErisGenerator<array{start_minute: int, end_minute: int}>
 */
function property31Interval(): ErisGenerator
{
    return Generators::map(
        static function (array $pair): array {
            [$a, $b] = $pair;

            $start = min($a, $b);
            $end = max($a, $b);

            if ($start === $end) {
                $end < 1440 ? $end++ : $start--;
            }

            return ['start_minute' => $start, 'end_minute' => $end];
        },
        Generators::tuple(
            Generators::choose(0, 1440),
            Generators::choose(0, 1440),
        ),
    );
}

/**
 * A valid day: 0..3 non-overlapping well-formed intervals. Candidates are drawn
 * then greedily dropped where they would overlap an already-kept one, so the
 * result always satisfies the per-day rules of Requirement 32.5.
 *
 * @return ErisGenerator<list<array{start_minute: int, end_minute: int}>>
 */
function property31ValidDay(): ErisGenerator
{
    return Generators::map(
        static function (array $candidates): array {
            $kept = [];

            foreach ($candidates as $candidate) {
                $overlaps = false;

                foreach ($kept as $existing) {
                    // Half-open overlap: touching intervals do not overlap.
                    if ($candidate['start_minute'] < $existing['end_minute']
                        && $existing['start_minute'] < $candidate['end_minute']) {
                        $overlaps = true;
                        break;
                    }
                }

                if (! $overlaps) {
                    $kept[] = $candidate;
                }

                if (count($kept) === 3) {
                    break;
                }
            }

            return $kept;
        },
        Generators::seq(property31Interval()),
    );
}

/**
 * A complete valid weekly schedule: an independent valid day for each of the
 * seven days. Produced empty often enough — every day may draw zero intervals —
 * that the always-closed schedule is reached by construction, which is the case
 * that proves a replacement can clear every prior interval.
 *
 * @return ErisGenerator<array<string, list<array{start_minute: int, end_minute: int}>>>
 */
function property31ValidSchedule(): ErisGenerator
{
    return Generators::map(
        static function (array $days): array {
            $schedule = [];
            foreach (PROPERTY31_DAYS as $index => $day) {
                $schedule[$day] = $days[$index];
            }

            return $schedule;
        },
        Generators::tuple(
            property31ValidDay(),
            property31ValidDay(),
            property31ValidDay(),
            property31ValidDay(),
            property31ValidDay(),
            property31ValidDay(),
            property31ValidDay(),
        ),
    );
}

/**
 * An invalid schedule carrying exactly one interval-rule violation, drawn from
 * the four kinds the rules of Requirement 32.5 forbid. Every variant is still
 * shape-valid (string day keys, integer pairs) so it reaches the service's
 * interval-rule validation rather than being rejected earlier by shape.
 *
 * @return ErisGenerator<array<string, list<array{start_minute: int, end_minute: int}>>>
 */
function property31InvalidSchedule(): ErisGenerator
{
    return Generators::map(
        static function (array $parts): array {
            [$kind, $day, $a, $b] = $parts;

            $start = min($a, $b);
            $end = max($a, $b);
            if ($start === $end) {
                $end < 1439 ? $end++ : $start--;
            }

            $intervals = match ($kind) {
                // Two intervals of the same day that overlap under the half-open
                // reading: the second starts strictly before the first ends.
                'overlap' => [
                    ['start_minute' => $start, 'end_minute' => $end],
                    ['start_minute' => $start, 'end_minute' => min($end + 1, 1440)],
                ],
                // Four intervals in one day: more than the maximum of three.
                'too_many' => [
                    ['start_minute' => 0, 'end_minute' => 100],
                    ['start_minute' => 100, 'end_minute' => 200],
                    ['start_minute' => 200, 'end_minute' => 300],
                    ['start_minute' => 300, 'end_minute' => 400],
                ],
                // A bound outside the closed 0..1440 range.
                'out_of_range' => [
                    ['start_minute' => $start, 'end_minute' => 1441],
                ],
                // Start at or after end (a zero-length or inverted span).
                default => [
                    ['start_minute' => $end, 'end_minute' => $start],
                ],
            };

            return [$day => $intervals];
        },
        Generators::tuple(
            Generators::elements(['overlap', 'too_many', 'out_of_range', 'bad_order']),
            Generators::elements(PROPERTY31_DAYS),
            Generators::choose(0, 1440),
            Generators::choose(0, 1440),
        ),
    );
}

beforeEach(function (): void {
    property31CreateSchema();
});

it('replaces the complete stored schedule with exactly the submitted intervals when accepted', function (): void {
    $this->limitTo(100)->forAll(
        property31ValidSchedule(),
        property31ValidSchedule(),
        Generators::bool(),
    )->then(function (array $stored, array $submitted, bool $onBranch): void {
        DB::beginTransaction();

        try {
            $service = property31Service();
            $actor = property31Administrator();

            $restaurantId = property31SeedRestaurant();

            if ($onBranch) {
                $table = 'branch_hours';
                $ownerColumn = 'restaurant_branch_id';
                $ownerId = property31SeedBranch($restaurantId);
                $apply = fn (array $schedule) => $service->replaceBranchHours($actor, $ownerId, $schedule);
            } else {
                $table = 'opening_hours';
                $ownerColumn = 'restaurant_id';
                $ownerId = $restaurantId;
                $apply = fn (array $schedule) => $service->replaceOpeningHours($actor, $ownerId, $schedule);
            }

            // Establish an arbitrary prior stored schedule, then replace it.
            $apply($stored);
            $apply($submitted);

            // Exactly the submitted intervals are stored; no interval of the
            // prior stored schedule survives (Requirement 11.7, 32.5, 32.6).
            expect(property31StoredByDay($table, $ownerColumn, $ownerId))
                ->toEqual(property31ExpectedByDay($submitted));
        } finally {
            DB::rollBack();
        }
    });
});

it('rejects an interval-rule violation with invalid_hours and leaves the stored schedule byte-unchanged', function (): void {
    $this->limitTo(100)->forAll(
        property31ValidSchedule(),
        property31InvalidSchedule(),
        Generators::bool(),
    )->then(function (array $stored, array $invalid, bool $onBranch): void {
        DB::beginTransaction();

        try {
            $service = property31Service();
            $actor = property31Administrator();

            $restaurantId = property31SeedRestaurant();

            if ($onBranch) {
                $table = 'branch_hours';
                $ownerColumn = 'restaurant_branch_id';
                $ownerId = property31SeedBranch($restaurantId);
                $apply = fn (array $schedule) => $service->replaceBranchHours($actor, $ownerId, $schedule);
            } else {
                $table = 'opening_hours';
                $ownerColumn = 'restaurant_id';
                $ownerId = $restaurantId;
                $apply = fn (array $schedule) => $service->replaceOpeningHours($actor, $ownerId, $schedule);
            }

            // A valid prior schedule the rejected submission must not disturb.
            $apply($stored);
            $before = property31StoredByDay($table, $ownerColumn, $ownerId);

            // The invalid submission is rejected specifically with invalid_hours.
            try {
                $apply($invalid);
                $this->fail('An interval-rule violation was accepted; expected invalid_hours.');
            } catch (ValidationException $exception) {
                expect($exception->errorCode())->toBe(SubmittedSchedule::ERROR_CODE);
            }

            // The stored schedule is byte-for-byte what it was, with no interval
            // of the submitted schedule applied (Requirement 11.8, 32.6).
            expect(property31StoredByDay($table, $ownerColumn, $ownerId))->toEqual($before);
        } finally {
            DB::rollBack();
        }
    });
});
