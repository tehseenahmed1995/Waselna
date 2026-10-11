<?php

declare(strict_types=1);

/*
 * Local_Dev_Environment topology: exactly six services (Requirement 24.1),
 * bind-mounted application source (Requirement 24.3), and PostgreSQL and Redis
 * data in the named volumes `pgdata` and `redisdata` (Requirements 24.4, 24.5).
 */

use Symfony\Component\Yaml\Yaml;

/**
 * @return array<string, mixed>
 */
function composeFile(): array
{
    /** @var array<string, mixed> $parsed */
    $parsed = Yaml::parseFile(dirname(__DIR__, 4).'/docker-compose.yml');

    return $parsed;
}

it('defines exactly the six documented services', function (): void {
    /** @var array<string, mixed> $services */
    $services = composeFile()['services'];
    $names = array_keys($services);
    sort($names);

    expect($names)->toBe(['backend', 'frontend', 'postgres', 'queue-worker', 'redis', 'reverb']);
});

it('bind-mounts the application source into every Delivery_API container', function (string $service): void {
    /** @var array<string, array<string, mixed>> $services */
    $services = composeFile()['services'];

    expect($services[$service]['volumes'])->toContain('./backend:/var/www/html');
})->with(['backend', 'queue-worker', 'reverb']);

it('bind-mounts the Web_Client source into the frontend container', function (): void {
    /** @var array<string, array<string, mixed>> $services */
    $services = composeFile()['services'];

    expect($services['frontend']['volumes'])->toContain('./frontend:/app');
});

it('declares exactly the pgdata and redisdata named volumes', function (): void {
    /** @var array<string, mixed> $volumes */
    $volumes = composeFile()['volumes'];

    expect(array_keys($volumes))->toBe(['pgdata', 'redisdata']);
});

it('keeps PostgreSQL and Redis data in their named volumes', function (): void {
    /** @var array<string, array<string, mixed>> $services */
    $services = composeFile()['services'];

    expect($services['postgres']['volumes'])->toContain('pgdata:/var/lib/postgresql/data')
        ->and($services['redis']['volumes'])->toContain('redisdata:/data');
});

it('runs PostgreSQL with PostGIS', function (): void {
    /** @var array<string, array<string, mixed>> $services */
    $services = composeFile()['services'];

    expect($services['postgres']['image'])->toContain('postgis');
});

it('reads every environment-specific value from the environment file', function (string $service): void {
    /** @var array<string, array<string, mixed>> $services */
    $services = composeFile()['services'];

    expect($services[$service]['env_file'])->toContain('./backend/.env');
})->with(['backend', 'queue-worker', 'reverb']);
