<?php

declare(strict_types=1);

namespace App\Shared\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A Clock held at one instant and moved only when a test says so.
 *
 * Bound in place of {@see SystemClock} whenever a test exercises a deadline: the
 * 60-minute Access_Token and 30-day Refresh_Token expiry, the 15-minute sign-in
 * lockout, the 24-hour email-change window, the 120/600-second Dispatch offer
 * timers, and the ±60 / −300-second location timestamp window. Advancing the
 * clock replaces waiting.
 */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $instant;

    public function __construct(DateTimeInterface $instant)
    {
        $this->instant = self::toUtc($instant);
    }

    /**
     * @param  string  $instant  any expression `DateTimeImmutable` accepts, for
     *                           example `2025-01-01T00:00:00Z`
     */
    public static function at(string $instant): self
    {
        return new self(new DateTimeImmutable($instant, new DateTimeZone('UTC')));
    }

    public function nowUtc(): DateTimeImmutable
    {
        return $this->instant;
    }

    public function setTo(DateTimeInterface $instant): void
    {
        $this->instant = self::toUtc($instant);
    }

    /**
     * Moves the clock forward, or backward when $seconds is negative.
     */
    public function advanceSeconds(int $seconds): void
    {
        if ($seconds === 0) {
            return;
        }

        $interval = new DateInterval(sprintf('PT%dS', abs($seconds)));

        $this->instant = $seconds < 0
            ? $this->instant->sub($interval)
            : $this->instant->add($interval);
    }

    public function advanceMinutes(int $minutes): void
    {
        $this->advanceSeconds($minutes * 60);
    }

    public function advanceDays(int $days): void
    {
        $this->advanceSeconds($days * 86400);
    }

    public function advance(DateInterval $interval): void
    {
        if ($interval->invert === 1) {
            throw new InvalidArgumentException(
                'Use advanceSeconds() with a negative value to move the clock backward.'
            );
        }

        $this->instant = $this->instant->add($interval);
    }

    private static function toUtc(DateTimeInterface $instant): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
