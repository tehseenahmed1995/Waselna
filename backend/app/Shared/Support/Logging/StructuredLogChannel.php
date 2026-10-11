<?php

declare(strict_types=1);

namespace App\Shared\Support\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;

/**
 * The `structured` log channel: one StreamHandler writing the single-line JSON
 * entries of Requirement 27.3.
 *
 * Container-resolved, which is how the formatter reaches the CorrelationContext
 * of the request being served and the secret source that keeps Requirement 27.7
 * satisfied. Deployed environments point `stream` at `php://stderr` so the
 * container runtime collects entries; locally it defaults to a file under
 * `storage/logs`.
 */
final readonly class StructuredLogChannel
{
    public function __construct(private StructuredJsonFormatter $formatter) {}

    /**
     * @param  array{stream?: string|null, path?: string|null, level?: string|null, name?: string|null, bubble?: bool}  $config
     */
    public function __invoke(array $config = []): LoggerInterface
    {
        // An empty LOG_STRUCTURED_STREAM arrives as '' rather than null; treat it
        // as absent so the channel falls back to the configured file path instead
        // of attempting to open the working directory in append mode.
        $stream = $config['stream'] ?? null;
        $stream = ($stream === '' || $stream === null) ? null : $stream;

        $path = $config['path'] ?? null;
        $path = ($path === '' || $path === null) ? null : $path;

        $target = $stream ?? $path ?? 'php://stderr';

        $handler = new StreamHandler(
            $target,
            $this->level($config['level'] ?? null),
            $config['bubble'] ?? true,
        );

        $handler->setFormatter($this->formatter);

        return new Logger(
            $config['name'] ?? 'delivery-api',
            [$handler],
            [new PsrLogMessageProcessor],
        );
    }

    /**
     * An unrecognised `LOG_LEVEL` records everything rather than nothing: losing
     * entries to a typo would be the worse failure for the Observability_Stack.
     */
    private function level(?string $name): Level
    {
        return match (strtolower((string) $name)) {
            'emergency' => Level::Emergency,
            'alert' => Level::Alert,
            'critical' => Level::Critical,
            'error' => Level::Error,
            'warning' => Level::Warning,
            'notice' => Level::Notice,
            'info' => Level::Info,
            default => Level::Debug,
        };
    }
}
