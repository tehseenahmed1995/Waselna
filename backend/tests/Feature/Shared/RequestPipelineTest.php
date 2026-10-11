<?php

declare(strict_types=1);

/*
 * The cross-cutting request pipeline and the single error renderer.
 *
 * Requirement 20.2 (JSON in and out), 20.3 (status per failure class), 20.4
 * (stable code, message, one entry per rejected field naming the rule), 20.10
 * (data / total / next-cursor envelope), 27.4 (Correlation_Identifier echoed or
 * generated), 27.9 (`internal_error` with no trace in the body), 28.3 (429 with
 * Retry-After), 28.4 (cross-origin access limited to the configured origins),
 * 28.9 (the four security headers).
 */

use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ConflictException;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Domain\Page;
use App\Shared\Http\Envelope;
use App\Shared\Support\Correlation\CorrelationId;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

use function Pest\Laravel\getJson;

/**
 * Registers a probe endpoint inside the documented pipeline. Endpoints of the
 * Delivery_API arrive with their own module tasks; the pipeline itself is what is
 * under test here.
 */
function probe(string $uri, Closure $handler, string ...$middleware): void
{
    Route::middleware(['api', ...$middleware])
        ->prefix('api/v1')
        ->get($uri, $handler);
}

it('echoes a supplied Correlation_Identifier and generates one otherwise', function (): void {
    probe('probe', static fn (): array => ['ok' => true]);

    $echoed = getJson('/api/v1/probe', [CorrelationId::HEADER => 'supplied-identifier-01']);
    $generated = getJson('/api/v1/probe');

    expect($echoed->headers->get(CorrelationId::HEADER))->toBe('supplied-identifier-01')
        ->and($generated->headers->get(CorrelationId::HEADER))->toBeString()
        ->and($generated->headers->get(CorrelationId::HEADER))->not->toBe('');
});

it('sets the four security headers on a success and on a failure', function (): void {
    probe('probe', static fn (): array => ['ok' => true]);
    probe('probe-failure', static function (): array {
        throw ConflictException::because('invalid_state_transition', 'Not from here.');
    });

    foreach (['/api/v1/probe', '/api/v1/probe-failure'] as $uri) {
        $response = getJson($uri);

        foreach ([
            'Strict-Transport-Security',
            'X-Content-Type-Options',
            'X-Frame-Options',
            'Content-Security-Policy',
        ] as $header) {
            expect($response->headers->get($header))->not->toBeNull("{$header} missing from {$uri}");
        }
    }
});

it('wraps a single resource and a collection in the documented envelope', function (): void {
    probe('resource', static fn (): array => ['id' => '01JB7Q2N4Z', 'state' => 'pending_payment']);
    probe('collection', static fn (): array => Envelope::collection(new Page(
        [['id' => '01JB7Q2N4Z']],
        137,
        'eyJ0ZXN0Ijp0cnVlfQ',
    )));

    getJson('/api/v1/resource')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['data' => ['id' => '01JB7Q2N4Z', 'state' => 'pending_payment']]);

    getJson('/api/v1/collection')
        ->assertOk()
        ->assertExactJson([
            'data' => [['id' => '01JB7Q2N4Z']],
            'meta' => ['total' => 137, 'next_cursor' => 'eyJ0ZXN0Ijp0cnVlfQ'],
        ]);
});

it('renders each DomainException family under its catalogued status', function (
    Closure $thrower,
    int $status,
    string $code,
): void {
    probe('failure', $thrower);

    getJson('/api/v1/failure')
        ->assertStatus($status)
        ->assertJsonPath('error.code', $code)
        ->assertJsonPath('error.fields', []);
})->with([
    'validation' => [
        static fn (): array => throw ValidationException::because('item_unavailable', 'That Menu_Item is unavailable.'),
        422,
        'item_unavailable',
    ],
    'conflict' => [
        static fn (): array => throw ConflictException::because('invalid_state_transition', 'Not from here.'),
        409,
        'invalid_state_transition',
    ],
    'unauthenticated' => [
        static fn (): array => throw AuthorizationException::unauthenticated(),
        401,
        'unauthenticated',
    ],
    'forbidden' => [
        static fn (): array => throw AuthorizationException::forbidden(),
        403,
        'forbidden',
    ],
    'non-disclosing not found' => [
        static fn (): array => throw AuthorizationException::notFound('order_not_found', 'No such Order.'),
        404,
        'order_not_found',
    ],
]);

it('names every rejected field and the rule it violated', function (): void {
    probe('validated', static function (): array {
        Validator::make(
            ['quantity' => 50],
            ['quantity' => 'integer|between:1,20', 'menu_item_id' => 'required'],
        )->validate();

        return [];
    });

    $response = getJson('/api/v1/validated');

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    expect($response->json('error.fields'))->toBe([
        ['field' => 'quantity', 'rule' => 'between:1,20'],
        ['field' => 'menu_item_id', 'rule' => 'required'],
    ]);
});

it('collapses an unhandled exception to internal_error without a trace', function (): void {
    probe('boom', static function (): array {
        throw new RuntimeException('a failure the Platform did not anticipate');
    });

    $response = getJson('/api/v1/boom');

    $response->assertStatus(500)
        ->assertJsonPath('error.code', 'internal_error')
        ->assertJsonMissingPath('error.trace');

    expect($response->getContent())->not->toContain('RequestPipelineTest')
        ->and($response->getContent())->not->toContain('a failure the Platform did not anticipate');
});

it('answers a path that matches no endpoint in the same envelope', function (): void {
    getJson('/api/v1/nothing-here')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('rejects a request beyond the per-minute allowance with a Retry-After header', function (): void {
    config(['waselna.throttle.limits.unauthenticated' => 2]);

    probe('limited', static fn (): array => ['ok' => true]);

    getJson('/api/v1/limited')->assertOk();
    getJson('/api/v1/limited')->assertOk();

    $rejected = getJson('/api/v1/limited');

    $rejected->assertStatus(429)->assertJsonPath('error.code', 'rate_limit_exceeded');

    expect((int) $rejected->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and((int) $rejected->headers->get('Retry-After'))->toBeLessThanOrEqual(60);
});

it('grants cross-origin access to a configured origin only', function (): void {
    config(['waselna.cors.allowed_origins' => ['https://app.waselna.test']]);

    probe('origin', static fn (): array => ['ok' => true]);

    $granted = getJson('/api/v1/origin', ['Origin' => 'https://app.waselna.test']);
    $denied = getJson('/api/v1/origin', ['Origin' => 'https://evil.example']);

    expect($granted->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.waselna.test')
        ->and($denied->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});
