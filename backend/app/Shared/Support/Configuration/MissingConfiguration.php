<?php

declare(strict_types=1);

namespace App\Shared\Support\Configuration;

use RuntimeException;

/**
 * Thrown while the application boots when a required environment variable is
 * absent or empty. Throwing during provider registration terminates startup,
 * and the message names every offending variable together with its grouping
 * heading (Requirements 24.9, 44.8).
 */
final class MissingConfiguration extends RuntimeException
{
    /**
     * @param  list<RequiredVariable>  $missing
     */
    public function __construct(public readonly array $missing)
    {
        parent::__construct(self::messageFor($missing));
    }

    /**
     * @param  list<RequiredVariable>  $missing
     */
    public static function messageFor(array $missing): string
    {
        $named = implode(', ', array_map(
            static fn (RequiredVariable $variable): string => $variable->describe(),
            $missing,
        ));

        return sprintf(
            'Startup terminated: %s required environment %s absent or empty: %s. '
            .'Set every listed variable in the environment file; .env.example lists each one '
            .'with a non-secret placeholder and docs/architecture/local.md documents it.',
            count($missing),
            count($missing) === 1 ? 'variable is' : 'variables are',
            $named,
        );
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(
            static fn (RequiredVariable $variable): string => $variable->name,
            $this->missing,
        );
    }
}
