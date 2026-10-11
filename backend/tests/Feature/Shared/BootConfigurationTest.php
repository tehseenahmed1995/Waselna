<?php

declare(strict_types=1);

/*
 * A failed boot.
 *
 * Requirements 24.9 and 44.8: when a required environment variable is absent or
 * empty at application boot, startup terminates with a message naming the
 * variable and its grouping heading.
 *
 * The check lives in a service provider registered before every other binding,
 * so this test boots a second application whose environment file is empty and
 * whose target variable is withheld from the process environment, and asserts
 * that registering the configured providers never completes.
 */

use App\Shared\Support\Configuration\MissingConfiguration;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\Facades\Facade;

/**
 * Replaces one variable in the process environment for the duration of one test
 * — withheld entirely when the replacement is null — and returns the closure
 * that puts the original value back.
 */
function replaceEnvironmentValue(string $name, ?string $value): Closure
{
    $fromEnv = $_ENV[$name] ?? null;
    $fromServer = $_SERVER[$name] ?? null;
    $fromPutenv = getenv($name);

    unset($_ENV[$name], $_SERVER[$name]);
    putenv($name);

    if ($value !== null) {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }

    return static function () use ($name, $fromEnv, $fromServer, $fromPutenv): void {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);

        if ($fromEnv !== null) {
            $_ENV[$name] = $fromEnv;
        }

        if ($fromServer !== null) {
            $_SERVER[$name] = $fromServer;
        }

        if ($fromPutenv !== false) {
            putenv($name.'='.$fromPutenv);
        }
    };
}

/**
 * An environment directory holding an empty environment file, so a withheld
 * variable cannot be supplied by the developer's own `.env`.
 */
function emptyEnvironmentPath(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'waselna-boot-'.bin2hex(random_bytes(6));

    mkdir($path);
    file_put_contents($path.DIRECTORY_SEPARATOR.'.env', "# Deliberately empty.\n");

    return $path;
}

/**
 * Boots a second application from the committed bootstrap file and leaves the
 * application under test as the container and facade root afterwards, whether
 * the boot terminated or not.
 */
function bootWithEmptyEnvironmentFile(): void
{
    $current = app();
    $environmentPath = emptyEnvironmentPath();

    try {
        /** @var Application $booting */
        $booting = require base_path('bootstrap/app.php');

        $booting->useEnvironmentPath($environmentPath);
        $booting->bootstrapWith([
            LoadEnvironmentVariables::class,
            LoadConfiguration::class,
            RegisterProviders::class,
        ]);
    } finally {
        Container::setInstance($current);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($current);

        @unlink($environmentPath.DIRECTORY_SEPARATOR.'.env');
        @rmdir($environmentPath);
    }
}

it('terminates startup naming the absent variable and its grouping heading', function (
    string $variable,
    string $heading,
): void {
    $restore = replaceEnvironmentValue($variable, null);

    try {
        expect(static fn () => bootWithEmptyEnvironmentFile())
            ->toThrow(MissingConfiguration::class, sprintf('%s (grouping heading %s)', $variable, $heading));
    } finally {
        $restore();
    }
})->with([
    'a database credential' => ['DB_PASSWORD', 'DATABASE'],
    'a Realtime_Gateway secret' => ['REVERB_APP_SECRET', 'REVERB'],
]);

it('terminates startup when a required variable is present but empty', function (string $value): void {
    // Requirement 44.8 treats an empty value as absent, which is the misconfiguration
    // a developer actually commits: the line is in the environment file with nothing
    // after the equals sign.
    $restore = replaceEnvironmentValue('WEB_CLIENT_ORIGINS', $value);

    try {
        expect(static fn () => bootWithEmptyEnvironmentFile())
            ->toThrow(MissingConfiguration::class, 'WEB_CLIENT_ORIGINS (grouping heading API)');
    } finally {
        $restore();
    }
})->with([
    'empty' => [''],
    'whitespace only' => ['   '],
]);

it('boots when every required variable is present', function (): void {
    bootWithEmptyEnvironmentFile();
})->throwsNoExceptions();
