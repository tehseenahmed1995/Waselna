<?php

declare(strict_types=1);

namespace App\Shared\Http\Health;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\ConnectionResolverInterface as DatabaseResolver;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The three reachability checks behind the health endpoint: the PostgreSQL
 * connection, the Cache_Store connection, and the queue connection
 * (Requirement 27.1).
 *
 * Each check is the cheapest round trip the driver offers, so the whole report
 * stays well inside the 1-second budget of Requirement 27.1: a `select 1`
 * against the default database connection, a single read against the default
 * cache store, and a queue-depth read against the default queue connection. A
 * driver failure is caught, recorded at error level in the Observability_Stack,
 * and reported as an unreachable dependency rather than raised, because a health
 * check whose own failure is a 500 tells an operator nothing (Requirement 27.2).
 *
 * The connections are resolved on every call, never cached, so an operator
 * polling the endpoint observes recovery as soon as a dependency returns.
 */
final readonly class HealthProbe
{
    /**
     * The dependency names the health body uses. They are part of the response
     * contract, so they are fixed here rather than derived from a driver name.
     */
    public const string POSTGRES = 'postgres';

    public const string CACHE = 'cache';

    public const string QUEUE = 'queue';

    /**
     * Read by the Cache_Store check. Nothing is written, and a miss is a healthy
     * answer: what is under test is the connection, not the contents.
     */
    private const string PROBE_KEY = 'health:probe';

    public function __construct(
        private DatabaseResolver $database,
        private CacheFactory $cache,
        private QueueFactory $queue,
        private LoggerInterface $logger,
    ) {}

    public function report(): HealthReport
    {
        return new HealthReport([
            $this->check(self::POSTGRES, fn () => $this->database->connection()->selectOne('select 1')),
            $this->check(self::CACHE, fn () => $this->cache->store()->get(self::PROBE_KEY)),
            $this->check(self::QUEUE, fn () => $this->queue->connection()->size()),
        ]);
    }

    private function check(string $name, Closure $probe): DependencyStatus
    {
        try {
            $probe();

            return DependencyStatus::reachable($name);
        } catch (Throwable $failure) {
            $this->logger->error('Health check found a dependency unreachable.', [
                'dependency' => $name,
                'failure' => $failure::class,
                'reason' => $failure->getMessage(),
            ]);

            return DependencyStatus::unreachable($name);
        }
    }
}
