<?php

declare(strict_types=1);

/*
 * The boot-time configuration contract: an absent or empty required
 * environment variable terminates startup with a message naming the variable
 * and its grouping heading (Requirements 24.9, 44.8), and every required
 * variable sits under exactly one of the nine headings (Requirement 44.3).
 */

use App\Shared\Support\Configuration\ConfigurationGroup;
use App\Shared\Support\Configuration\MissingConfiguration;
use App\Shared\Support\Configuration\RequiredConfiguration;
use App\Shared\Support\Configuration\RequiredVariable;

/**
 * A value set that satisfies every required variable.
 *
 * @return array<string, string>
 */
function satisfyingEnvironment(): array
{
    return array_fill_keys(RequiredConfiguration::names(), 'placeholder');
}

it('accepts a complete set of values', function (): void {
    expect(RequiredConfiguration::missing(satisfyingEnvironment()))->toBe([]);
});

it('declares the nine grouping headings and puts every variable under one of them', function (): void {
    $headings = array_map(
        static fn (ConfigurationGroup $group): string => $group->value,
        ConfigurationGroup::cases(),
    );

    expect($headings)->toBe([
        'APP', 'DATABASE', 'REDIS', 'CACHE', 'QUEUE', 'REVERB', 'FILESYSTEM', 'MAIL', 'API',
    ]);

    $variables = RequiredConfiguration::variables();

    expect($variables)->not->toBeEmpty()
        ->and(RequiredConfiguration::names())->toBe(array_unique(RequiredConfiguration::names()));

    foreach ($variables as $variable) {
        expect($variable->group)->toBeInstanceOf(ConfigurationGroup::class);
    }
});

it('names the variable and its grouping heading when it is absent', function (): void {
    $values = satisfyingEnvironment();
    unset($values['DB_PASSWORD']);

    expect(static fn () => RequiredConfiguration::assert($values))
        ->toThrow(MissingConfiguration::class, 'DB_PASSWORD (grouping heading DATABASE)');
});

it('treats an empty and a whitespace-only value as absent', function (string $value): void {
    $values = satisfyingEnvironment();
    $values['REVERB_APP_SECRET'] = $value;

    expect(static fn () => RequiredConfiguration::assert($values))
        ->toThrow(MissingConfiguration::class, 'REVERB_APP_SECRET (grouping heading REVERB)');
})->with([
    'empty' => [''],
    'whitespace only' => ["  \t "],
]);

it('names every absent variable in one message', function (): void {
    $values = satisfyingEnvironment();
    unset($values['APP_KEY'], $values['REDIS_HOST'], $values['WEB_CLIENT_ORIGINS']);

    $missing = RequiredConfiguration::missing($values);

    expect(array_map(static fn (RequiredVariable $v): string => $v->name, $missing))
        ->toBe(['APP_KEY', 'REDIS_HOST', 'WEB_CLIENT_ORIGINS']);

    expect(MissingConfiguration::messageFor($missing))
        ->toContain('3 required environment variables are absent or empty')
        ->toContain('APP_KEY (grouping heading APP)')
        ->toContain('REDIS_HOST (grouping heading REDIS)')
        ->toContain('WEB_CLIENT_ORIGINS (grouping heading API)');
});

it('lists every required variable in the committed example environment file', function (): void {
    $example = (string) file_get_contents(dirname(__DIR__, 5).'/.env.example');

    foreach (RequiredConfiguration::names() as $name) {
        expect($example)->toMatch('/^'.preg_quote($name, '/').'=/m');
    }
});
