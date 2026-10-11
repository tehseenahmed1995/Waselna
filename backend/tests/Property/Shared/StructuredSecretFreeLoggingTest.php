<?php

declare(strict_types=1);

/*
 * Feature: Waselna, Property 62: Logs are structured and free of secrets
 *
 * **Validates: Requirements 27.3, 27.7, 27.9**
 *
 * For any request — including one carrying a password, an Access_Token, a
 * Refresh_Token, and payment instrument values in its body, its headers, and its
 * query string — every entry the Delivery_API emits is a single-line JSON object
 * carrying a UTC timestamp, a severity level, a message, the request
 * Correlation_Identifier, and the acting Platform user identifier where one is
 * authenticated, and carries none of those secret values. An unhandled exception
 * yields an API_Error with code `internal_error` whose body excludes the stack
 * trace while the trace is recorded in the Observability_Stack.
 *
 * The entries are read back from the `structured` channel's own stream rather
 * than from a log double, so the channel, the formatter, and the redactor are all
 * under test exactly as configured.
 */

use App\Shared\Support\Correlation\CorrelationContext;
use App\Shared\Support\Correlation\CorrelationId;
use App\Shared\Support\Logging\SecretRedactor;
use Eris\Generator;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\postJson;

uses(TestTrait::class);

/**
 * Secret values over the URL-unreserved alphabet: letters, digits, and `-._~`.
 *
 * Constrained deliberately. A value from this alphabet survives a query string,
 * an `Authorization: Bearer <token>` header, and a JSON body unchanged, so an
 * entry that still holds the secret holds it verbatim and the property tests
 * redaction rather than transport encoding. The lower bound of 8 characters is the
 * shortest secret the Platform issues or accepts.
 */
function secretFreeLogSecret(int $minimum = 8, int $maximum = 40): Generator
{
    $alphabet = str_split(
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~'
    );

    return Generators::map(
        static fn (array $characters): string => implode('', $characters),
        Generators::bind(
            Generators::choose($minimum, $maximum),
            static fn (int $length): Generator => Generators::vector(
                $length,
                Generators::elements($alphabet),
            ),
        ),
    );
}

/**
 * A digit string, for the Primary Account Number and the security code of a
 * payment instrument.
 */
function secretFreeLogDigits(int $minimum, int $maximum): Generator
{
    return Generators::map(
        static fn (array $digits): string => implode('', $digits),
        Generators::bind(
            Generators::choose($minimum, $maximum),
            static fn (int $length): Generator => Generators::vector(
                $length,
                Generators::elements(str_split('0123456789')),
            ),
        ),
    );
}

/**
 * Every path in a decoded entry whose key names a secret and whose value is not
 * the redaction placeholder. Requirement 27.7 holds only while this is empty.
 *
 * @return list<string>
 */
function secretFreeLogSurvivors(mixed $value, string $path = 'entry'): array
{
    if (! is_array($value)) {
        return [];
    }

    $redactor = new SecretRedactor;
    $survivors = [];

    foreach ($value as $key => $item) {
        $childPath = $path.'.'.$key;

        if (is_string($key) && $redactor->isSensitiveKey($key)) {
            if ($item !== SecretRedactor::PLACEHOLDER) {
                $survivors[] = $childPath.' = '.var_export($item, true);
            }

            continue;
        }

        $survivors = [...$survivors, ...secretFreeLogSurvivors($item, $childPath)];
    }

    return $survivors;
}

it('emits single-line JSON entries carrying no secret the request presented', function (): void {
    // One writable target per test run; the channel is forgotten after each
    // request so the handler closes and flushes before the file is read.
    $logPath = sys_get_temp_dir().DIRECTORY_SEPARATOR
        .'waselna-property-62-'.bin2hex(random_bytes(6)).'.log';

    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['structured'],
        'logging.channels.structured.stream' => $logPath,
        'logging.channels.structured.level' => 'debug',
        // The property is about log entries, not about the allowance of
        // Requirement 28.3, which Property 63 owns.
        'waselna.throttle.limits.unauthenticated' => 1_000_000,
        'waselna.throttle.limits.authenticated' => 1_000_000,
    ]);

    // A probe endpoint that logs the way a Controller does: free text that
    // interpolates request values, plus a context holding the whole request, its
    // headers, and its query string.
    Route::middleware('api')->prefix('api/v1')->post('property-62-probe', function (Request $request): array {
        // Stands in for AuthenticateBearer, which is the only writer of the
        // acting Platform user identifier: an authenticated request names its
        // actor, an anonymous one leaves it null.
        $actorId = $request->input('actor_id');

        if (is_string($actorId) && $actorId !== '') {
            app(CorrelationContext::class)->identifyActor($actorId);
        }

        Log::log((string) $request->input('level', 'info'), sprintf(
            'checkout attempt by %s presenting %s with password %s for card %s',
            (string) $request->input('email'),
            (string) $request->input('access_token'),
            (string) $request->input('password'),
            (string) $request->input('payment.card_number'),
        ), [
            'request' => $request->all(),
            'headers' => $request->headers->all(),
            'query' => $request->query(),
            'password' => (string) $request->input('password'),
            'payment' => [
                'card_number' => (string) $request->input('payment.card_number'),
                'cvv' => (string) $request->input('payment.cvv'),
            ],
        ]);

        if ($request->boolean('unhandled')) {
            throw new RuntimeException(sprintf(
                'the Platform could not charge card %s with token %s',
                (string) $request->input('payment.card_number'),
                (string) $request->input('access_token'),
            ));
        }

        return ['ok' => true];
    });

    $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];

    $this->limitTo(100)->forAll(
        Generators::associative([
            'password' => secretFreeLogSecret(),
            'accessToken' => secretFreeLogSecret(24, 64),
            'refreshToken' => secretFreeLogSecret(24, 64),
            'cardNumber' => secretFreeLogDigits(13, 19),
            'cvv' => secretFreeLogDigits(3, 4),
            'actorDigits' => secretFreeLogDigits(1, 9),
            'level' => Generators::elements($levels),
            'unhandled' => Generators::bool(),
            'authenticated' => Generators::bool(),
            'supplyCorrelationId' => Generators::bool(),
        ])
    )->then(function (array $input) use ($logPath, $levels): void {
        // Values long enough for the literal rule to reach, wherever they appear.
        $secrets = [
            $input['password'],
            $input['accessToken'],
            $input['refreshToken'],
            $input['cardNumber'],
        ];

        $headers = [
            'Authorization' => 'Bearer '.$input['accessToken'],
            'X-Refresh-Token' => $input['refreshToken'],
        ];

        if ($input['supplyCorrelationId']) {
            $headers[CorrelationId::HEADER] = 'property-62-'.substr($input['accessToken'], 0, 12);
        }

        $actorId = $input['authenticated'] ? 'user_'.$input['actorDigits'] : null;

        Log::forgetChannel('stack');
        Log::forgetChannel('structured');

        // The context is a singleton that outlives one generated input, so each
        // input starts from an anonymous request the way a fresh process would.
        app(CorrelationContext::class)->identifyActor(null);

        $response = postJson(
            '/api/v1/property-62-probe'
                .'?access_token='.rawurlencode($input['accessToken'])
                .'&card_number='.rawurlencode($input['cardNumber'])
                .'&note='.rawurlencode('presented token '.$input['accessToken']),
            [
                'email' => 'customer@example.test',
                'password' => $input['password'],
                'password_confirmation' => $input['password'],
                'refresh_token' => $input['refreshToken'],
                'note' => 'the password '.$input['password'].' was rejected',
                'payment' => [
                    'card_number' => $input['cardNumber'],
                    'cvv' => $input['cvv'],
                ],
                'level' => $input['level'],
                'unhandled' => $input['unhandled'],
                'actor_id' => $actorId,
            ],
            $headers,
        );

        // Closing the channel flushes the handler's stream before it is read.
        Log::forgetChannel('stack');
        Log::forgetChannel('structured');

        clearstatcache(true, $logPath);
        $written = is_file($logPath) ? (string) file_get_contents($logPath) : '';
        @unlink($logPath);

        $lines = array_values(array_filter(
            explode("\n", $written),
            static fn (string $line): bool => trim($line) !== '',
        ));

        expect($lines)->not->toBeEmpty();

        $correlationId = $response->headers->get(CorrelationId::HEADER);

        expect($correlationId)->toBeString()->not->toBe('');

        $levelsSeen = [];
        $tracesRecorded = 0;
        $actorsNamed = 0;

        foreach ($lines as $line) {
            /** @var array<string, mixed> $entry */
            $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

            // Requirement 27.3: the documented shape, one entry per line.
            expect($entry)->toBeArray()
                ->and($entry)->toHaveKeys(['timestamp', 'level', 'message', 'correlation_id', 'user_id'])
                ->and($entry['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/')
                ->and($entry['message'])->toBeString()
                ->and($entry['correlation_id'])->toBe($correlationId)
                ->and(in_array($entry['level'], $levels, true))->toBeTrue();

            // Requirement 27.7: no secret the request carried, under a key or in
            // free text, and nothing surviving under a secret-naming key.
            foreach ($secrets as $secret) {
                expect(str_contains($line, $secret))->toBeFalse();
            }

            expect(secretFreeLogSurvivors($entry))->toBe([]);

            // Requirement 27.3: the acting Platform user identifier where one is
            // authenticated. An entry emitted before authentication resolves
            // carries null; no entry ever carries a different identifier.
            if ($actorId === null) {
                expect($entry['user_id'])->toBeNull();
            } else {
                expect([null, $actorId])->toContain($entry['user_id']);

                if ($entry['user_id'] === $actorId) {
                    $actorsNamed++;
                }
            }

            $levelsSeen[] = $entry['level'];

            $trace = $entry['context']['exception']['trace'] ?? null;

            if (is_string($trace) && $trace !== '') {
                $tracesRecorded++;
            }
        }

        expect($levelsSeen)->toContain($input['level']);

        if ($actorId !== null) {
            expect($actorsNamed)->toBeGreaterThan(0);
        }

        if ($input['unhandled']) {
            // Requirement 27.9: `internal_error`, no trace in the body, the trace
            // in the Observability_Stack.
            $body = (string) $response->getContent();

            expect($response->getStatusCode())->toBe(500)
                ->and($response->json('error.code'))->toBe('internal_error')
                ->and($response->json('error.trace'))->toBeNull()
                ->and(str_contains($body, 'RuntimeException'))->toBeFalse()
                ->and($tracesRecorded)->toBeGreaterThan(0);

            foreach ($secrets as $secret) {
                expect(str_contains($body, $secret))->toBeFalse();
            }

            return;
        }

        expect($response->getStatusCode())->toBe(200);
    });
});
