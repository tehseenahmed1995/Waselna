<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Connectors\ConnectorInterface;

/**
 * Hands the one {@see CapturingQueue} instance to the QueueManager so the test
 * that installed it keeps a reference to the payloads it collects.
 */
final readonly class CapturingQueueConnector implements ConnectorInterface
{
    public function __construct(private CapturingQueue $queue) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): QueueContract
    {
        return $this->queue;
    }
}
