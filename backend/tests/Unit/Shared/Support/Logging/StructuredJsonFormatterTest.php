<?php

declare(strict_types=1);

/*
 * StructuredJsonFormatter: Requirement 27.3 — every log entry is a single-line
 * JSON object carrying a UTC timestamp, a severity level, a message, the request
 * Correlation_Identifier, and the acting Platform user identifier where one is
 * authenticated; Requirement 27.7 — carrying none of the secret values;
 * Requirement 27.9 — an unhandled exception's stack trace is recorded here rather
 * than in the response body.
 */

use App\Shared\Support\Correlation\CorrelationContext;
use App\Shared\Support\Correlation\CorrelationId;
use App\Shared\Support\Logging\SecretRedactor;
use App\Shared\Support\Logging\SecretSource;
use App\Shared\Support\Logging\StructuredJsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * @param  list<string>  $secrets
 */
function structuredSecrets(array $secrets = []): SecretSource
{
    return new class($secrets) implements SecretSource
    {
        /**
         * @param  list<string>  $secrets
         */
        public function __construct(private array $secrets) {}

        /**
         * @return list<string>
         */
        public function secrets(): array
        {
            return $this->secrets;
        }
    };
}

/**
 * @param  array<string, mixed>  $context
 */
function structuredRecord(string $message = 'an event', array $context = [], Level $level = Level::Info): LogRecord
{
    return new LogRecord(
        new DateTimeImmutable('2025-03-04 15:06:07.123456', new DateTimeZone('Asia/Riyadh')),
        'delivery-api',
        $level,
        $message,
        $context,
    );
}

function structuredFormatter(?CorrelationContext $context = null, ?SecretSource $secrets = null): StructuredJsonFormatter
{
    return new StructuredJsonFormatter(
        $context ?? new CorrelationContext,
        new SecretRedactor,
        $secrets ?? structuredSecrets(),
    );
}

/**
 * @return array<string, mixed>
 */
function structuredEntry(string $line): array
{
    /** @var array<string, mixed> $entry */
    $entry = json_decode(trim($line), true, flags: JSON_THROW_ON_ERROR);

    return $entry;
}

it('emits one line carrying the five documented keys', function (): void {
    $context = new CorrelationContext;
    $context->assign(CorrelationId::fromString('01JB7Q2N4Z5X6Y7W8V9T0S1R2Q'));
    $context->identifyActor('01JB7Q2N4Z5X6Y7W8V9T0S1R2P');

    $line = structuredFormatter($context)->format(structuredRecord());

    expect(substr_count($line, "\n"))->toBe(1)
        ->and(str_ends_with($line, "\n"))->toBeTrue()
        ->and(structuredEntry($line))->toMatchArray([
            // 15:06 Riyadh (UTC+3) is 12:06 UTC.
            'timestamp' => '2025-03-04T12:06:07.123456Z',
            'level' => 'info',
            'message' => 'an event',
            'correlation_id' => '01JB7Q2N4Z5X6Y7W8V9T0S1R2Q',
            'user_id' => '01JB7Q2N4Z5X6Y7W8V9T0S1R2P',
            'channel' => 'delivery-api',
        ]);
});

it('names the Platform user as null where no user is authenticated', function (): void {
    $context = new CorrelationContext;
    $context->assign(CorrelationId::fromString('01JB7Q2N4Z5X6Y7W8V9T0S1R2Q'));

    $entry = structuredEntry(structuredFormatter($context)->format(structuredRecord()));

    expect($entry)->toHaveKey('user_id')
        ->and($entry['user_id'])->toBeNull();
});

it('stays on one line when the message and the context span lines', function (): void {
    $line = structuredFormatter()->format(structuredRecord(
        "first\nsecond",
        ['trace' => "frame one\nframe two"],
    ));

    $entry = structuredEntry($line);

    expect(substr_count($line, "\n"))->toBe(1)
        ->and($entry['message'])->toBe("first\nsecond")
        ->and($entry['context']['trace'])->toBe("frame one\nframe two");
});

it('redacts a secret carried under a secret-naming key', function (): void {
    $entry = structuredEntry(structuredFormatter()->format(structuredRecord(
        'sign-in rejected',
        ['email' => 'customer@example.test', 'password' => 'a-very-secret-password'],
    )));

    expect($entry['context']['password'])->toBe(SecretRedactor::PLACEHOLDER)
        ->and($entry['context']['email'])->toBe('customer@example.test');
});

it('redacts a secret the request carried, message included', function (): void {
    $token = '01JB7Q2N4Z5X6Y7W8V9T0S1R2Q-token';

    $line = structuredFormatter(null, structuredSecrets([$token]))->format(structuredRecord(
        "token {$token} rejected",
        ['presented' => $token],
    ));

    $entry = structuredEntry($line);

    expect($line)->not->toContain($token)
        ->and($entry['message'])->toBe('token '.SecretRedactor::PLACEHOLDER.' rejected')
        ->and($entry['context']['presented'])->toBe(SecretRedactor::PLACEHOLDER);
});

it('records the stack trace of an unhandled exception', function (): void {
    $entry = structuredEntry(structuredFormatter()->format(structuredRecord(
        'boom',
        ['exception' => new RuntimeException('boom')],
        Level::Error,
    )));

    expect($entry['level'])->toBe('error')
        ->and($entry['context']['exception']['class'])->toBe(RuntimeException::class)
        ->and($entry['context']['exception']['trace'])->toBeString()
        ->and($entry['context']['exception']['trace'])->not->toBe('');
});

it('omits an empty context and an empty extra', function (): void {
    $entry = structuredEntry(structuredFormatter()->format(structuredRecord()));

    expect($entry)->not->toHaveKey('context')
        ->and($entry)->not->toHaveKey('extra');
});

it('formats a batch as one line per record', function (): void {
    $formatted = structuredFormatter()
        ->formatBatch([structuredRecord('first'), structuredRecord('second')]);

    expect(substr_count($formatted, "\n"))->toBe(2);
});
