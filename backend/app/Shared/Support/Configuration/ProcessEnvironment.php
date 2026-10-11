<?php

declare(strict_types=1);

namespace App\Shared\Support\Configuration;

/**
 * Reads raw values out of the process environment, which at boot already holds
 * the environment file the framework loaded plus anything the container or the
 * shell injected (Requirement 44.1). Values are returned unparsed; the boot
 * check only asks whether a value is present and non-empty, so no secret is
 * interpreted, logged, or echoed here.
 */
final class ProcessEnvironment
{
    /**
     * @param  list<string>  $names
     * @return array<string, string|null>
     */
    public static function values(array $names): array
    {
        $values = [];

        foreach ($names as $name) {
            $values[$name] = self::value($name);
        }

        return $values;
    }

    public static function value(string $name): ?string
    {
        $raw = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if ($raw === false || $raw === null) {
            return null;
        }

        if (is_bool($raw)) {
            return $raw ? 'true' : 'false';
        }

        return is_scalar($raw) ? (string) $raw : null;
    }
}
