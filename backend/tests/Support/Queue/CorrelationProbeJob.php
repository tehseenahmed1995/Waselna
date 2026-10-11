<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A Job with no behaviour, dispatched only so its payload can be inspected.
 *
 * The queued Jobs of the Delivery_API arrive with their own module tasks; what
 * Requirement 27.5 fixes is that any Job a request dispatches carries the
 * Correlation_Identifier of that request, whatever the Job does.
 */
final class CorrelationProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $marker) {}

    public function handle(): void
    {
        // Intentionally empty.
    }
}
