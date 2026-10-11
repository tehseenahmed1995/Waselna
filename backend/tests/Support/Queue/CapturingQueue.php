<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Queue\QueueManager;

/**
 * A queue connection that builds the real Job payload and keeps it instead of
 * handing it to a backend.
 *
 * Requirement 27.5 is about what a queued Job *carries*, so the payload is what a
 * test has to look at. The `sync` connection cannot answer that question: it runs
 * the Job inline and then fires JobProcessed, which clears the
 * CorrelationContext of the request that is still being served. This connection
 * keeps the enqueue-only behaviour of a real backend — payload created through
 * `Queue::createPayloadUsing`, nothing executed — and exposes the payloads for
 * assertions.
 */
final class CapturingQueue extends BaseQueue implements QueueContract
{
    public const CONNECTION = 'capturing';

    /**
     * Every payload pushed since the last reset, decoded.
     *
     * @var list<array<string, mixed>>
     */
    private array $pushed = [];

    /**
     * Binds this connection into the application and makes it the default, so a
     * plain `dispatch()` reaches it.
     */
    public static function install(Application $app): self
    {
        $queue = new self;
        $queue->setContainer($app);
        $queue->setConnectionName(self::CONNECTION);

        $config = $app->make('config');
        $config->set('queue.connections.'.self::CONNECTION, [
            'driver' => self::CONNECTION,
            'queue' => 'default',
        ]);
        $config->set('queue.default', self::CONNECTION);

        /** @var QueueManager $manager */
        $manager = $app->make('queue');
        $manager->extend(
            self::CONNECTION,
            static fn (): ConnectorInterface => new CapturingQueueConnector($queue),
        );

        return $queue;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pushed(): array
    {
        return $this->pushed;
    }

    public function forgetPushed(): void
    {
        $this->pushed = [];
    }

    public function size($queue = null): int
    {
        return count($this->pushed);
    }

    public function push($job, $data = '', $queue = null): mixed
    {
        return $this->pushRaw(
            $this->createPayload($job, $queue ?? 'default', $data),
            $queue,
        );
    }

    public function pushRaw($payload, $queue = null, array $options = []): mixed
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);

        $this->pushed[] = $decoded;

        return $decoded['uuid'] ?? null;
    }

    public function later($delay, $job, $data = '', $queue = null): mixed
    {
        return $this->pushRaw(
            $this->createPayload($job, $queue ?? 'default', $data, $delay),
            $queue,
        );
    }

    public function pop($queue = null): mixed
    {
        return null;
    }
}
