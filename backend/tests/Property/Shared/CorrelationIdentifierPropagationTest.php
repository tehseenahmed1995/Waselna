<?php

declare(strict_types=1);

// Feature: food-delivery-platform, Property 61: The Correlation_Identifier is echoed or generated and propagated everywhere

/*
 * *For any* request, the Delivery_API uses a supplied Correlation_Identifier
 * header or generates one, returns it in the response headers, and includes that
 * identical value in every log entry, every queued Job, and every Realtime_Event
 * produced by that request.
 *
 * **Validates: Requirements 27.4, 27.5**
 *
 * The three producers the identifier has to reach all read one CorrelationContext
 * (the log formatter, the queue payload hook, and — once task 25.1 lands the
 * Broadcaster adapter — the Realtime_Event payload). The log entries and the
 * queued Job payloads are asserted directly; the context value the request hands
 * back is asserted too, because that single value is what the broadcaster reads.
 *
 * Time is read through the injected Clock, held frozen here, and identifiers
 * through the injected IdGenerator, seeded as a known sequence so a generated
 * identifier is distinguishable from an echoed one.
 */

use App\Shared\Support\Clock;
use App\Shared\Support\Correlation\CorrelationContext;
use App\Shared\Support\Correlation\CorrelationId;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\IdGenerator;
use App\Shared\Support\SequenceIdGenerator;
use Eris\Facade;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Support\Queue\CapturingQueue;
use Tests\Support\Queue\CorrelationProbeJob;

use function Pest\Laravel\getJson;

/**
 * The alphabet a Correlation_Identifier may hold: letters, digits, and the three
 * separators a ULID, a UUID, or a trace identifier needs.
 *
 * @return list<string>
 */
function correlationAlphabet(): array
{
    return array_merge(
        range('a', 'z'),
        range('A', 'Z'),
        range('0', '9'),
        ['.', '_', '-'],
    );
}

/**
 * Strings of the accepted alphabet at a generated length, so every documented
 * length bound is reachable.
 */
function correlationStringsOfLength(int $minimum, int $maximum): ErisGenerator
{
    return Generators::bind(
        Generators::choose($minimum, $maximum),
        static fn (int $length): ErisGenerator => Generators::map(
            static fn (array $characters): string => implode('', $characters),
            Generators::vector($length, Generators::elements(correlationAlphabet())),
        ),
    );
}

/**
 * The header a client may send, drawn from every shape the Delivery_API has to
 * answer: absent, acceptable at each length bound, padded with whitespace,
 * empty or whitespace-only, too short, too long, and carrying a character that
 * would split a response header or a JSON log line.
 *
 * Directed rather than filtered: the forbidden character is spliced into the
 * middle of an otherwise acceptable value, so trimming cannot rescue it and no
 * generated input has to be discarded.
 */
function correlationHeaders(): ErisGenerator
{
    $forbidden = [' ', "\t", ';', ',', ':', '"', '@', '/', '\\', '%', '(', ')', '|', '=', '+', '*', '#', '?', '&', '<', '>', '{', '}'];

    return Generators::oneOf(
        Generators::constant(null),
        correlationStringsOfLength(CorrelationId::MIN_LENGTH, CorrelationId::MAX_LENGTH),
        Generators::constant(str_repeat('a', CorrelationId::MIN_LENGTH)),
        Generators::constant(str_repeat('z', CorrelationId::MAX_LENGTH)),
        Generators::map(
            static fn (string $value): string => '  '.$value.'  ',
            correlationStringsOfLength(CorrelationId::MIN_LENGTH, CorrelationId::MAX_LENGTH),
        ),
        Generators::elements(['', ' ', '   ', "\t"]),
        correlationStringsOfLength(1, CorrelationId::MIN_LENGTH - 1),
        correlationStringsOfLength(CorrelationId::MAX_LENGTH + 1, CorrelationId::MAX_LENGTH + 56),
        Generators::map(
            static function (array $input): string {
                [$value, $character] = $input;
                $middle = intdiv(strlen($value), 2);

                return substr($value, 0, $middle).$character.substr($value, $middle);
            },
            Generators::tuple(
                correlationStringsOfLength(CorrelationId::MIN_LENGTH, CorrelationId::MAX_LENGTH - 1),
                Generators::elements($forbidden),
            ),
        ),
    );
}

/**
 * The value the Delivery_API has to echo, or null where it has to generate one:
 * the documented contract restated independently of the implementation. A header
 * is usable once surrounding whitespace is removed, where what remains is 8 to 64
 * characters of letters, digits, ".", "_", and "-".
 */
function correlationAcceptedFrom(?string $header): ?string
{
    if ($header === null) {
        return null;
    }

    $trimmed = trim($header);
    $length = strlen($trimmed);

    if ($length < CorrelationId::MIN_LENGTH || $length > CorrelationId::MAX_LENGTH) {
        return null;
    }

    return preg_match('/^[A-Za-z0-9._-]+$/', $trimmed) === 1 ? $trimmed : null;
}

it('echoes or generates the Correlation_Identifier and propagates that value into every log entry and every queued Job', function (): void {
    $identifiers = new SequenceIdGenerator;

    app()->instance(IdGenerator::class, $identifiers);
    app()->instance(Clock::class, FrozenClock::at('2025-01-01T00:00:00Z'));

    // The per-minute allowance of Requirement 28.3 is Property 63's subject; it
    // must not reject the requests this property generates.
    config(['waselna.throttle.limits.unauthenticated' => 1_000_000]);

    // Enqueue without executing, so the payload of Requirement 27.5 can be read
    // exactly as a Queue_Worker would receive it.
    $queue = CapturingQueue::install(app());

    // The structured channel of Requirement 27.3 writes to a readable file for
    // the duration of the property.
    $logPath = (string) tempnam(sys_get_temp_dir(), 'waselna-correlation-');

    config([
        'logging.default' => 'structured',
        'logging.channels.structured.stream' => $logPath,
    ]);

    Log::forgetChannel('structured');
    Log::forgetChannel('stack');

    Route::middleware('api')
        ->prefix('api/v1')
        ->get('correlation-probe', function (Request $request, CorrelationContext $context): array {
            $levels = ['debug', 'info', 'notice', 'warning', 'error'];
            $entries = (int) $request->query('entries', '1');
            $jobs = (int) $request->query('jobs', '1');

            for ($index = 0; $index < $entries; $index++) {
                Log::log($levels[$index % count($levels)], sprintf('probe entry %d', $index));
            }

            for ($index = 0; $index < $jobs; $index++) {
                CorrelationProbeJob::dispatch(sprintf('job-%d', $index));
            }

            // After the dispatches as well as before them, so an enqueue cannot
            // silently drop the identifier of the request still being served.
            Log::info('probe complete');

            return ['context' => $context->value()];
        });

    $bytesRead = 0;

    (new Facade)
        ->limitTo(100)
        ->forAll(
            correlationHeaders(),
            Generators::choose(1, 3),
            Generators::choose(1, 3),
        )
        ->then(function (
            ?string $header,
            int $entries,
            int $jobs,
        ) use ($identifiers, $queue, $logPath, &$bytesRead): void {
            $queue->forgetPushed();
            $issuedBefore = $identifiers->issuedCount();

            $response = getJson(
                sprintf('/api/v1/correlation-probe?entries=%d&jobs=%d', $entries, $jobs),
                $header === null ? [] : [CorrelationId::HEADER => $header],
            );

            $response->assertOk();

            $returned = $response->headers->get(CorrelationId::HEADER);
            $accepted = correlationAcceptedFrom($header);

            // Requirement 27.4: returned in the response headers, always.
            expect($returned)->toBeString()->and($returned)->not->toBe('');

            if ($accepted !== null) {
                // A usable header is the identifier; nothing is generated.
                expect($returned)->toBe($accepted)
                    ->and($identifiers->issuedCount())->toBe($issuedBefore);
            } else {
                // No usable header: exactly one identifier is generated, and it
                // is the generator's next value rather than anything derived
                // from what the client sent.
                expect($returned)->toBe($identifiers->peek($issuedBefore + 1))
                    ->and($identifiers->issuedCount())->toBe($issuedBefore + 1);
            }

            // The single value every producer of this request reads — the log
            // formatter, the queue payload hook, and the Realtime_Event payload.
            expect($response->json('data.context'))->toBe($returned);

            // Requirement 27.5, in the log entries of this request.
            $fresh = substr((string) file_get_contents($logPath), $bytesRead);
            $bytesRead += strlen($fresh);

            $lines = array_values(array_filter(
                explode("\n", $fresh),
                static fn (string $line): bool => trim($line) !== '',
            ));

            expect($lines)->toHaveCount($entries + 1);

            foreach ($lines as $line) {
                /** @var array<string, mixed> $entry */
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                expect($entry['correlation_id'] ?? null)->toBe($returned);
            }

            // Requirement 27.5, in the queued Jobs of this request.
            $payloads = $queue->pushed();

            expect($payloads)->toHaveCount($jobs);

            foreach ($payloads as $payload) {
                expect($payload['correlation_id'] ?? null)->toBe($returned);
            }
        });

    Log::forgetChannel('structured');
    @unlink($logPath);
});
