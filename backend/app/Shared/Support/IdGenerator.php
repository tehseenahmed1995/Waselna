<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * The single source of new identifiers.
 *
 * Requirement 40.7 requires an identifier type that admits no collision across
 * rows; every table's primary key is a ULID, which is collision-free in practice
 * and lexicographically sortable by creation time. Going through an interface
 * lets a test seed a known sequence and assert on exact identifiers.
 *
 * Implementations return the 26-character Crockford base32 canonical form.
 */
interface IdGenerator
{
    public function ulid(): string;
}
