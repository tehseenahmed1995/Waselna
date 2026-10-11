<?php

declare(strict_types=1);

// Feature: waselna, Property 63: Rate limits are enforced per scope with a Retry-After header

/*
 * For any request sequence within one minute, an authenticated Platform user is
 * permitted 60 requests per endpoint group and an unauthenticated client 20
 * requests on authentication endpoints, further requests being rejected with
 * HTTP status 429 and a `Retry-After` header, and this limiting is independent of
 * the failed sign-in attempt limit.
 *
 * **Validates: Requirements 28.3**
 *
 * The limiter is driven directly rather than through the HTTP kernel so the
 * window boundary comes from the injected Clock alone: a FrozenClock is advanced
 * by hand inside the minute, and no assertion here depends on wall-clock time.
 */

use App\Shared\Domain\Exceptions\ErrorCatalogue;
use App\Shared\Http\Middleware\ThrottleRequests;
use App\Shared\Support\Correlation\CorrelationContext;
use App\Shared\Support\FrozenClock;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

uses(TestTrait::class);

/**
 * The configured allowances of Requirement 28.3, held here so the property reads
 * the same numbers the Delivery_API is configured with.
 *
 * @return array{authenticated: int, unauthenticated: int, unauthenticated_auth_endpoints: int}
 */
function throttleLimits(): array
{
    return [
        'authenticated' => 60,
        'unauthenticated' => 60,
        'unauthenticated_auth_endpoints' => 20,
    ];
}

function throttleWindowSeconds(): int
{
    return 60;
}

/**
 * One limiter over a Cache_Store and a Clock the property owns.
 */
function throttleLimiter(
    Repository $store,
    FrozenClock $clock,
    CorrelationContext $correlation,
): ThrottleRequests {
    return new ThrottleRequests(
        $store,
        $clock,
        $correlation,
        throttleLimits(),
        throttleWindowSeconds(),
        'auth',
    );
}

/**
 * The allowance the limiter owes a subject on one endpoint group.
 */
function throttleAllowanceFor(bool $authenticated, string $scope): int
{
    $limits = throttleLimits();

    if ($authenticated) {
        return $limits['authenticated'];
    }

    return $scope === 'auth'
        ? $limits['unauthenticated_auth_endpoints']
        : $limits['unauthenticated'];
}

function throttledRequest(ThrottleRequests $limiter, string $scope, string $address): Response
{
    $request = Request::create('/api/v1/probe', 'GET', server: ['REMOTE_ADDR' => $address]);

    return $limiter->handle(
        $request,
        static fn (): Response => new JsonResponse(['data' => ['ok' => true]]),
        $scope,
    );
}

it('permits each endpoint group its own per-minute allowance and rejects beyond it with Retry-After', function (): void {
    $scopes = ['auth', 'catalog', 'orders', 'default'];

    $this->limitTo(100)->forAll(
        Generators::associative([
            'authenticated' => Generators::bool(),
            // Two distinct endpoint groups: the one driven to its allowance and
            // one that must keep its own.
            'scopes' => Generators::filter(
                static fn (array $pair): bool => $pair[0] !== $pair[1],
                Generators::tuple(
                    Generators::elements($scopes),
                    Generators::elements($scopes),
                ),
            ),
            'actorId' => Generators::elements(['01JB7Q2N4Z0000000000000001', '01JB7Q2N4Z0000000000000002']),
            'address' => Generators::elements(['203.0.113.7', '198.51.100.24', '192.0.2.201']),
            'excess' => Generators::choose(1, 5),
            'stepSeconds' => Generators::choose(0, 2),
            // The failed sign-in attempt counter of Requirement 1.7, seeded at
            // and beyond its own limit of 5.
            'signInFailures' => Generators::choose(0, 50),
            'email' => Generators::elements(['ada@waselna.test', 'sam@waselna.test']),
        ]),
    )->then(function (array $input): void {
        [$scope, $otherScope] = $input['scopes'];
        $authenticated = $input['authenticated'];
        $address = $input['address'];
        $window = throttleWindowSeconds();

        $clock = FrozenClock::at('2025-03-01T12:00:00Z');
        $store = new Repository(new ArrayStore());
        $correlation = new CorrelationContext();

        if ($authenticated) {
            $correlation->identifyActor($input['actorId']);
        }

        $failureKey = 'signin_fail:'.$input['email'];
        $store->put($failureKey, $input['signInFailures'], 900);

        $limiter = throttleLimiter($store, $clock, $correlation);
        $allowance = throttleAllowanceFor($authenticated, $scope);

        // The allowance itself, spread across the minute. Every advance is on the
        // injected Clock, and the total stays inside one window.
        $elapsed = 0;
        $statuses = [];
        $remaining = [];

        for ($sent = 1; $sent <= $allowance; $sent++) {
            $response = throttledRequest($limiter, $scope, $address);

            $statuses[] = $response->getStatusCode();
            $remaining[] = $response->headers->get('X-RateLimit-Remaining');

            if ($sent % 16 === 0) {
                $clock->advanceSeconds($input['stepSeconds']);
                $elapsed += $input['stepSeconds'];
            }
        }

        expect($statuses)->toBe(array_fill(0, $allowance, 200))
            ->and($remaining)->toBe(array_map('strval', range($allowance - 1, 0)));

        $clock->advanceSeconds($input['stepSeconds']);
        $elapsed += $input['stepSeconds'];
        expect($elapsed)->toBeLessThan($window);

        // Everything past the allowance, still inside the same minute.
        $rejectedStatuses = [];
        $retryAfter = [];
        $rejectedBodies = [];

        for ($sent = 1; $sent <= $input['excess']; $sent++) {
            $rejected = throttledRequest($limiter, $scope, $address);

            $rejectedStatuses[] = $rejected->getStatusCode();
            $retryAfter[] = $rejected->headers->get('Retry-After');
            $rejectedBodies[] = json_decode((string) $rejected->getContent(), true);
        }

        $expectedRetryAfter = (string) max(1, $window - $elapsed);

        expect($rejectedStatuses)->toBe(array_fill(0, $input['excess'], 429))
            ->and($retryAfter)->toBe(array_fill(0, $input['excess'], $expectedRetryAfter))
            ->and(array_column(array_column($rejectedBodies, 'error'), 'code'))
            ->toBe(array_fill(0, $input['excess'], ErrorCatalogue::RATE_LIMIT_EXCEEDED));

        // A second endpoint group keeps its own allowance: one group's traffic
        // never consumes another's.
        $otherGroup = throttledRequest($limiter, $otherScope, $address);

        expect($otherGroup->getStatusCode())->toBe(200)
            ->and($otherGroup->headers->get('X-RateLimit-Limit'))
            ->toBe((string) throttleAllowanceFor($authenticated, $otherScope));

        // The failed sign-in attempt counter is a separate counter: rate limiting
        // neither reads nor writes it, and its value never shortened the
        // allowance measured above.
        expect($store->get($failureKey))->toBe($input['signInFailures']);
    });
});
