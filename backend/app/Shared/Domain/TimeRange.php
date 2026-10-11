<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A half-open interval of UTC instants: the start is included, the end is not.
 *
 * Requirement 40.7 keeps every timestamp timezone-aware in UTC, so both bounds
 * are converted to UTC at construction. The half-open reading is what makes
 * back-to-back ranges — Restaurant opening hours on consecutive days, successive
 * Promotion windows — non-overlapping by construction, and it matches the
 * "from the activation timestamp until the expiry timestamp" wording of
 * Requirement 37.4.
 */
final readonly class TimeRange
{
    public DateTimeImmutable $start;

    public DateTimeImmutable $end;

    public function __construct(DateTimeInterface $start, DateTimeInterface $end)
    {
        $utcStart = self::toUtc($start);
        $utcEnd = self::toUtc($end);

        if ($utcStart >= $utcEnd) {
            throw new InvalidArgumentException(sprintf(
                'A time range must start strictly before it ends; received %s and %s.',
                self::format($utcStart),
                self::format($utcEnd),
            ));
        }

        $this->start = $utcStart;
        $this->end = $utcEnd;
    }

    public static function between(DateTimeInterface $start, DateTimeInterface $end): self
    {
        return new self($start, $end);
    }

    /**
     * True while $instant falls at or after the start and strictly before the
     * end.
     */
    public function contains(DateTimeInterface $instant): bool
    {
        $utcInstant = self::toUtc($instant);

        return $utcInstant >= $this->start && $utcInstant < $this->end;
    }

    /**
     * True when the two ranges share at least one instant. Ranges that merely
     * touch — one ending exactly where the other starts — do not overlap.
     */
    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function durationSeconds(): int
    {
        return $this->end->getTimestamp() - $this->start->getTimestamp();
    }

    public function equals(self $other): bool
    {
        return $this->start->getTimestamp() === $other->start->getTimestamp()
            && $this->start->format('u') === $other->start->format('u')
            && $this->end->getTimestamp() === $other->end->getTimestamp()
            && $this->end->format('u') === $other->end->format('u');
    }

    /**
     * ISO 8601 UTC bounds with second precision (Requirement 20.8).
     *
     * @return array{start: string, end: string}
     */
    public function toArray(): array
    {
        return [
            'start' => self::format($this->start),
            'end' => self::format($this->end),
        ];
    }

    private static function toUtc(DateTimeInterface $instant): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private static function format(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s\Z');
    }
}
