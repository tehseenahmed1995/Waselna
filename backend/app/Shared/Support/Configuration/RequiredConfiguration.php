<?php

declare(strict_types=1);

namespace App\Shared\Support\Configuration;

/**
 * The boot-time configuration contract of the Delivery_API.
 *
 * Every environment-specific value is read from the environment file, never
 * held as a literal in application code (Requirement 44.1). This class is the
 * single list of the variables the application cannot boot without, each under
 * exactly one of the nine grouping headings (Requirement 44.3). `assert()` is
 * called while the container registers its providers, so an absent or empty
 * variable terminates startup with a message naming the variable and its
 * heading (Requirements 24.9, 44.8).
 *
 * Framework-free by intent: the caller supplies the raw values, which keeps the
 * rule unit-testable without booting an application.
 */
final class RequiredConfiguration
{
    /**
     * @return list<RequiredVariable>
     */
    public static function variables(): array
    {
        $variables = [
            ConfigurationGroup::App->value => [
                'APP_NAME',
                'APP_ENV',
                'APP_KEY',
                'APP_DEBUG',
                'APP_URL',
                'APP_LOCALE',
                'LOG_CHANNEL',
                'LOG_LEVEL',
            ],
            ConfigurationGroup::Database->value => [
                'DB_CONNECTION',
                'DB_HOST',
                'DB_PORT',
                'DB_DATABASE',
                'DB_USERNAME',
                'DB_PASSWORD',
            ],
            ConfigurationGroup::Redis->value => [
                'REDIS_CLIENT',
                'REDIS_HOST',
                'REDIS_PORT',
                'REDIS_PASSWORD',
            ],
            ConfigurationGroup::Cache->value => [
                'CACHE_STORE',
                'CACHE_PREFIX',
            ],
            ConfigurationGroup::Queue->value => [
                'QUEUE_CONNECTION',
                'QUEUE_FAILED_DRIVER',
            ],
            ConfigurationGroup::Reverb->value => [
                'BROADCAST_CONNECTION',
                'REVERB_APP_ID',
                'REVERB_APP_KEY',
                'REVERB_APP_SECRET',
                'REVERB_HOST',
                'REVERB_PORT',
                'REVERB_SCHEME',
            ],
            ConfigurationGroup::Filesystem->value => [
                'FILESYSTEM_DISK',
            ],
            ConfigurationGroup::Mail->value => [
                'MAIL_MAILER',
                'MAIL_FROM_ADDRESS',
                'MAIL_FROM_NAME',
            ],
            ConfigurationGroup::Api->value => [
                'WEB_CLIENT_ORIGINS',
            ],
        ];

        $required = [];

        foreach ($variables as $heading => $names) {
            $group = ConfigurationGroup::from($heading);

            foreach ($names as $name) {
                $required[] = new RequiredVariable($name, $group);
            }
        }

        return $required;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(
            static fn (RequiredVariable $variable): string => $variable->name,
            self::variables(),
        );
    }

    /**
     * The required variables the given values leave absent or empty, in the
     * order the contract declares them.
     *
     * @param  array<string, string|null>  $values
     * @return list<RequiredVariable>
     */
    public static function missing(array $values): array
    {
        $missing = [];

        foreach (self::variables() as $variable) {
            if (! $variable->isSatisfiedBy($values[$variable->name] ?? null)) {
                $missing[] = $variable;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, string|null>  $values
     *
     * @throws MissingConfiguration
     */
    public static function assert(array $values): void
    {
        $missing = self::missing($values);

        if ($missing !== []) {
            throw new MissingConfiguration($missing);
        }
    }
}
