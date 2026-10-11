<?php

declare(strict_types=1);

namespace App\Shared\Support;

use DateTimeImmutable;

/**
 * The single source of the current instant.
 *
 * Every time-dependent rule — Access_Token and Refresh_Token expiry, Promotion
 * windows, opening-hours evaluation, Dispatch offer timers, location timestamp
 * windows — reads the clock through this interface and never calls `now()`,
 * `time()`, or `Carbon::now()` directly. That is what makes those rules
 * deterministic under test, a precondition for the property suite.
 *
 * Implementations return an instant in UTC (Requirement 40.7).
 */
interface Clock
{
    public function nowUtc(): DateTimeImmutable;
}
