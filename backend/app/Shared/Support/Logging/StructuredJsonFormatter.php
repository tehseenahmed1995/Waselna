<?php

declare(strict_types=1);

namespace App\Shared\Support\Logging;

use App\Shared\Support\Correlation\CorrelationContext;
use DateTimeZone;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Renders every log entry as the single-line JSON object of Requirement 27.3: a
 * UTC timestamp, a severity level, a message, the request Correlation_Identifier,
 * and the acting Platform user identifier where one is authenticated.
 *
 * The five documented keys are always present — `user_id` is null rather than
 * absent when no Platform user is authenticated — so a log pipeline can index the
 * shape without a per-entry branch.
 *
 * Single-line is structural, not a convention: `json_encode` without
 * `JSON_PRETTY_PRINT` escapes every newline inside a string, so a message or a
 * stack trace spanning lines still occupies exactly one output line, terminated by
 * the one newline this formatter appends.
 *
 * Secrets are removed on the way through (Requirement 27.7): the SecretRedactor
 * drops values under secret-naming keys and replaces literal occurrences of the
 * values the current request carried.
 */
final readonly class StructuredJsonFormatter implements FormatterInterface
{
    public function __construct(
        private CorrelationContext $correlation,
        private SecretRedactor $redactor,
        private SecretSource $secrets,
    ) {}

    public function format(LogRecord $record): string
    {
        $literals = $this->secrets->secrets();

        $entry = [
            'timestamp' => $record->datetime
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
            'level' => strtolower($record->level->getName()),
            'message' => $this->redactor->redactText($record->message, $literals),
            'correlation_id' => $this->correlation->value(),
            'user_id' => $this->correlation->actorId(),
            'channel' => $record->channel,
        ];

        $context = $this->redactor->redact($record->context, $literals);

        if (is_array($context) && $context !== []) {
            $entry['context'] = $context;
        }

        $extra = $this->redactor->redact($record->extra, $literals);

        if (is_array($extra) && $extra !== []) {
            $entry['extra'] = $extra;
        }

        $encoded = json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        if ($encoded === false) {
            // A last resort that still satisfies the one-line JSON contract.
            $encoded = json_encode([
                'timestamp' => $entry['timestamp'],
                'level' => $entry['level'],
                'message' => 'A log entry could not be encoded.',
                'correlation_id' => $entry['correlation_id'],
                'user_id' => $entry['user_id'],
                'channel' => $entry['channel'],
            ], JSON_THROW_ON_ERROR);
        }

        return $encoded."\n";
    }

    /**
     * @param  array<array-key, LogRecord>  $records
     */
    public function formatBatch(array $records): string
    {
        $formatted = '';

        foreach ($records as $record) {
            $formatted .= $this->format($record);
        }

        return $formatted;
    }
}
