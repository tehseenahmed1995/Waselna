<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Support\Str;

/**
 * The production IdGenerator: monotonic ULIDs in canonical 26-character form.
 */
final class UlidGenerator implements IdGenerator
{
    public function ulid(): string
    {
        return (string) Str::ulid();
    }
}
