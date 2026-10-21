<?php

declare(strict_types=1);

// Unit: Waselna, task 9.1: Restaurant_Status lifecycle (transition table and
// publication prerequisites).

/*
 * Framework-free unit tests for the two Domain components the lifecycle rests
 * on (task 9.1; Requirements 59.3, 59.5, 59.6):
 *
 *  - RestaurantStatus: the permitted-transition table permits exactly the five
 *    documented edges and nothing else — not a self-edge, not an unknown value,
 *    not a reversed edge (Requirement 59.3, 59.6);
 *  - PublishPrerequisites: a Restaurant is publishable only when all three
 *    prerequisites hold, and the unmet-condition list names every unmet
 *    condition and only the unmet ones, in a fixed order (Requirement 59.5).
 *
 * No database and no container: these types are pure, which is what lets the
 * exhaustive enumeration below run as a plain Unit test. The exhaustive property
 * form is Property 51, written separately in task 9.2.
 */

use App\Modules\Catalog\Domain\PublishPrerequisites;
use App\Modules\Catalog\Domain\PublishReadiness;
use App\Modules\Catalog\Domain\RestaurantStatus;

/**
 * The five permitted edges of Requirement 59.3, as ordered `[from, to]` pairs.
 *
 * @return list<array{string, string}>
 */
function waselnaPermittedStatusEdges(): array
{
    return [
        [RestaurantStatus::DRAFT, RestaurantStatus::PENDING_REVIEW],
        [RestaurantStatus::PENDING_REVIEW, RestaurantStatus::PUBLISHED],
        [RestaurantStatus::PENDING_REVIEW, RestaurantStatus::DRAFT],
        [RestaurantStatus::PUBLISHED, RestaurantStatus::SUSPENDED],
        [RestaurantStatus::SUSPENDED, RestaurantStatus::PUBLISHED],
    ];
}

it('permits exactly the five documented Restaurant_Status edges', function (): void {
    $permitted = waselnaPermittedStatusEdges();

    // Every documented edge is permitted.
    foreach ($permitted as [$from, $to]) {
        expect(RestaurantStatus::canTransition($from, $to))->toBeTrue();
    }

    // Every ordered pair over the four statuses that is not one of the five is
    // refused, including each status to itself.
    foreach (RestaurantStatus::ALL as $from) {
        foreach (RestaurantStatus::ALL as $to) {
            $isPermitted = in_array([$from, $to], $permitted, true);

            expect(RestaurantStatus::canTransition($from, $to))->toBe($isPermitted);
        }
    }

    // Exactly five permitted edges, no more.
    $count = 0;
    foreach (RestaurantStatus::ALL as $from) {
        foreach (RestaurantStatus::ALL as $to) {
            if (RestaurantStatus::canTransition($from, $to)) {
                $count++;
            }
        }
    }
    expect($count)->toBe(5);
});

it('refuses a transition touching an unknown Restaurant_Status value', function (): void {
    expect(RestaurantStatus::canTransition('unknown', RestaurantStatus::PUBLISHED))->toBeFalse()
        ->and(RestaurantStatus::canTransition(RestaurantStatus::DRAFT, 'archived'))->toBeFalse()
        ->and(RestaurantStatus::isValid('unknown'))->toBeFalse()
        ->and(RestaurantStatus::isValid(RestaurantStatus::PUBLISHED))->toBeTrue();
});

it('requires publication prerequisites only for a target of published', function (): void {
    expect(RestaurantStatus::requiresPublishPrerequisites(RestaurantStatus::PUBLISHED))->toBeTrue()
        ->and(RestaurantStatus::requiresPublishPrerequisites(RestaurantStatus::PENDING_REVIEW))->toBeFalse()
        ->and(RestaurantStatus::requiresPublishPrerequisites(RestaurantStatus::DRAFT))->toBeFalse()
        ->and(RestaurantStatus::requiresPublishPrerequisites(RestaurantStatus::SUSPENDED))->toBeFalse();
});

it('treats a Restaurant as publishable only when all three prerequisites hold', function (): void {
    $ready = new PublishReadiness(true, true, true);

    expect(PublishPrerequisites::isPublishable($ready))->toBeTrue()
        ->and(PublishPrerequisites::unmetConditions($ready))->toBe([]);
});

it('names every unmet condition and only the unmet ones in a fixed order', function (): void {
    // Enumerate all eight combinations of the three booleans; assert the unmet
    // list is exactly the fixed-order subset of names whose fact is false.
    foreach ([true, false] as $branch) {
        foreach ([true, false] as $item) {
            foreach ([true, false] as $zone) {
                $readiness = new PublishReadiness($branch, $item, $zone);

                $expected = [];
                if (! $branch) {
                    $expected[] = PublishPrerequisites::NO_ACTIVE_BRANCH;
                }
                if (! $item) {
                    $expected[] = PublishPrerequisites::NO_AVAILABLE_MENU_ITEM;
                }
                if (! $zone) {
                    $expected[] = PublishPrerequisites::NO_ZONE_ON_ACTIVE_BRANCH;
                }

                expect(PublishPrerequisites::unmetConditions($readiness))->toBe($expected)
                    ->and(PublishPrerequisites::isPublishable($readiness))->toBe($expected === []);
            }
        }
    }
});
