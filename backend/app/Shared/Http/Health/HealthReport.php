<?php

declare(strict_types=1);

namespace App\Shared\Http\Health;

/**
 * The result of one health check: the state of the PostgreSQL connection, the
 * Cache_Store connection, and the queue connection (Requirement 27.1).
 *
 * The report is healthy only when every checked dependency answered. One
 * unreachable dependency degrades the whole report, and the names of the
 * unreachable dependencies are listed so the caller reads them without walking
 * the map (Requirement 27.2).
 */
final readonly class HealthReport
{
    public const string HEALTHY = 'healthy';

    public const string DEGRADED = 'degraded';

    /**
     * @param  list<DependencyStatus>  $dependencies
     */
    public function __construct(public array $dependencies) {}

    public function isHealthy(): bool
    {
        foreach ($this->dependencies as $dependency) {
            if (! $dependency->reachable) {
                return false;
            }
        }

        return true;
    }

    public function status(): string
    {
        return $this->isHealthy() ? self::HEALTHY : self::DEGRADED;
    }

    /**
     * @return list<string>
     */
    public function unreachable(): array
    {
        $names = [];

        foreach ($this->dependencies as $dependency) {
            if (! $dependency->reachable) {
                $names[] = $dependency->name;
            }
        }

        return $names;
    }

    /**
     * @return array{status: string, dependencies: array<string, string>, unreachable: list<string>}
     */
    public function toArray(): array
    {
        $dependencies = [];

        foreach ($this->dependencies as $dependency) {
            $dependencies[$dependency->name] = $dependency->state();
        }

        return [
            'status' => $this->status(),
            'dependencies' => $dependencies,
            'unreachable' => $this->unreachable(),
        ];
    }
}
