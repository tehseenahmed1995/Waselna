<?php

declare(strict_types=1);

namespace App\Shared\Support\Logging;

/**
 * The secret values the current unit of work carries, handed to the
 * SecretRedactor so literal occurrences are replaced wherever they appear in a
 * log entry (Requirement 27.7).
 *
 * Kept an interface because the sources differ: an HTTP request exposes them in
 * its body, query string, and headers, while a console command or a queued Job
 * has none.
 */
interface SecretSource
{
    /**
     * @return list<string>
     */
    public function secrets(): array;
}
