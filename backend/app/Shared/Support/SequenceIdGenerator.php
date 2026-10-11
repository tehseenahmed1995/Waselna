<?php

declare(strict_types=1);

namespace App\Shared\Support;

use InvalidArgumentException;
use LogicException;

/**
 * An IdGenerator that hands out a predictable, ascending sequence.
 *
 * Bound in place of {@see UlidGenerator} whenever a test needs to know an
 * identifier before the code under test creates it, or needs to force an
 * improbable event such as the Order_Number collision of Requirement 8.9 by
 * queueing the same value twice.
 *
 * The values are canonical 26-character Crockford base32 strings, so they pass
 * the same column constraints and validation rules as a real ULID. They sort
 * below every real ULID, which keeps a seeded sequence visibly distinct.
 */
final class SequenceIdGenerator implements IdGenerator
{
    private const CROCKFORD_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const IDENTIFIER_LENGTH = 26;

    private const IDENTIFIER_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    private int $issued = 0;

    /**
     * @var list<string>
     */
    private array $queued = [];

    public function ulid(): string
    {
        if ($this->queued !== []) {
            return array_shift($this->queued);
        }

        $this->issued++;

        return $this->encode($this->issued);
    }

    /**
     * Hands out the given identifiers, in order, ahead of the generated
     * sequence.
     */
    public function queue(string ...$identifiers): void
    {
        foreach ($identifiers as $identifier) {
            if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    '"%s" is not a canonical %d-character Crockford base32 identifier.',
                    $identifier,
                    self::IDENTIFIER_LENGTH,
                ));
            }

            $this->queued[] = $identifier;
        }
    }

    /**
     * The nth identifier this generator produces from its own sequence, without
     * consuming it — for arranging an expectation before the act.
     */
    public function peek(int $position): string
    {
        if ($position < 1) {
            throw new InvalidArgumentException(
                sprintf('A sequence position starts at 1; received %d.', $position)
            );
        }

        return $this->encode($position);
    }

    public function issuedCount(): int
    {
        return $this->issued;
    }

    public function reset(): void
    {
        $this->issued = 0;
        $this->queued = [];
    }

    private function encode(int $sequence): string
    {
        if ($sequence < 1) {
            throw new LogicException('A sequence number starts at 1.');
        }

        $digits = '';
        $remaining = $sequence;

        do {
            $digits = self::CROCKFORD_ALPHABET[$remaining % 32].$digits;
            $remaining = intdiv($remaining, 32);
        } while ($remaining > 0);

        return str_pad($digits, self::IDENTIFIER_LENGTH, '0', STR_PAD_LEFT);
    }
}
