<?php

declare(strict_types=1);

// Feature: waselna, task 7.4: SubmittedSchedule interval-rule validation and invalid_hours details.

/*
 * Unit tests for the framework-free SubmittedSchedule parser (task 7.4;
 * Requirements 11.8, 32.6). The parser is the single place that holds the
 * interval rules of Requirement 32 criterion 5 and the rejection shape of
 * criterion 6, so these tests pin:
 *
 *  - a conforming submission produces a WeeklySchedule with exactly the
 *    submitted intervals and raises nothing;
 *  - each kind of violation (bad bounds, start>=end, >3 per day, overlap,
 *    unknown day) raises invalid_hours;
 *  - a violation carries one per-field detail for each violating interval and
 *    no detail for a conforming interval;
 *  - the parser touches no database and no framework type — it is exercised as a
 *    pure Domain unit.
 */

use App\Modules\Catalog\Domain\DayOfWeek;
use App\Modules\Catalog\Domain\SubmittedSchedule;
use App\Modules\Catalog\Domain\WeeklySchedule;
use App\Shared\Domain\Exceptions\ValidationException;

function interval(int $start, int $end): array
{
    return ['start_minute' => $start, 'end_minute' => $end];
}

it('accepts a conforming schedule and returns exactly the submitted intervals', function (): void {
    $schedule = SubmittedSchedule::validate([
        'monday' => [interval(540, 720), interval(780, 1020)],
        'friday' => [interval(0, 1440)],
    ]);

    expect($schedule)->toBeInstanceOf(WeeklySchedule::class);

    $monday = $schedule->intervalsOn(DayOfWeek::Monday);
    expect($monday)->toHaveCount(2)
        ->and($monday[0]->startMinute)->toBe(540)
        ->and($monday[0]->endMinute)->toBe(720)
        ->and($monday[1]->startMinute)->toBe(780);

    expect($schedule->intervalsOn(DayOfWeek::Friday))->toHaveCount(1);
    // A day with no submitted interval is closed.
    expect($schedule->intervalsOn(DayOfWeek::Sunday))->toBe([]);
});

it('accepts an empty submission as an always-closed schedule', function (): void {
    $schedule = SubmittedSchedule::validate([]);

    expect($schedule->isEmpty())->toBeTrue();
});

it('accepts touching intervals because the reading is half-open', function (): void {
    $schedule = SubmittedSchedule::validate([
        'tuesday' => [interval(540, 720), interval(720, 900)],
    ]);

    expect($schedule->intervalsOn(DayOfWeek::Tuesday))->toHaveCount(2);
});

it('rejects a start minute at or after the end with invalid_hours and a per-interval detail', function (): void {
    try {
        SubmittedSchedule::validate(['monday' => [interval(600, 600)]]);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errorCode())->toBe('invalid_hours')
            ->and($e->fieldErrors())->toHaveKey('monday.0')
            ->and($e->fieldErrors()['monday.0'])->toContain('start_not_before_end');
    }
});

it('rejects a minute outside the closed range 0 to 1440', function (): void {
    try {
        SubmittedSchedule::validate(['monday' => [interval(-1, 100)], 'tuesday' => [interval(0, 1441)]]);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errorCode())->toBe('invalid_hours')
            ->and($e->fieldErrors()['monday.0'])->toContain('minute_out_of_range')
            ->and($e->fieldErrors()['tuesday.0'])->toContain('minute_out_of_range');
    }
});

it('rejects more than three intervals for one day', function (): void {
    try {
        SubmittedSchedule::validate([
            'wednesday' => [interval(0, 60), interval(120, 180), interval(240, 300), interval(360, 420)],
        ]);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errorCode())->toBe('invalid_hours')
            ->and($e->fieldErrors()['wednesday'])->toContain('too_many_intervals');
    }
});

it('flags both members of an overlapping pair, each with its own detail', function (): void {
    try {
        SubmittedSchedule::validate(['thursday' => [interval(540, 720), interval(700, 900)]]);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errorCode())->toBe('invalid_hours')
            ->and($e->fieldErrors()['thursday.0'])->toContain('overlapping_interval')
            ->and($e->fieldErrors()['thursday.1'])->toContain('overlapping_interval');
    }
});

it('rejects an unknown day of week', function (): void {
    try {
        SubmittedSchedule::validate(['funday' => [interval(0, 60)]]);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errorCode())->toBe('invalid_hours')
            ->and($e->fieldErrors()['funday'])->toContain('unknown_day_of_week');
    }
});

it('carries no detail for a conforming interval alongside a violating one', function (): void {
    try {
        SubmittedSchedule::validate([
            'monday' => [interval(540, 720)],      // conforming
            'tuesday' => [interval(900, 800)],     // start >= end
        ]);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->fieldErrors())->not->toHaveKey('monday.0')
            ->and($e->fieldErrors())->toHaveKey('tuesday.0');
    }
});
