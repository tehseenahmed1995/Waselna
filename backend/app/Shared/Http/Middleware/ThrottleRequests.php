<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Domain\Exceptions\ErrorCatalogue;
use App\Shared\Http\ApiError;
use App\Shared\Http\Envelope;
use App\Shared\Support\Clock;
use App\Shared\Support\Correlation\CorrelationContext;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pipeline position 6. Per-minute rate limiting with `Retry-After` on rejection
 * (Requirement 28.3).
 *
 *  - 60 requests per minute per authenticated Platform user per endpoint group.
 *  - 20 requests per minute per unauthenticated client on the `auth` endpoint
 *    group.
 *  - 60 requests per minute per unauthenticated client on any other group, keyed
 *    by client address.
 *
 * Counters live in the Cache_Store as `throttle:{scope}:{subject}` with a
 * companion `:window` entry holding the end of the running window, which is what
 * makes `Retry-After` exact rather than a fixed 60. Both entries carry the window
 * length as their time-to-live, so a window resets itself with no scheduled
 * cleanup. The scope is the endpoint group, passed as the middleware parameter,
 * so one group's traffic cannot consume another group's allowance.
 *
 * The window boundary is read from the injected Clock, never from wall-clock
 * time, so a property test can advance time deterministically.
 *
 * Independent of the failed sign-in attempt limit of Requirement 1.7, which is a
 * separate `signin_fail:{emailLower}` counter owned by the Auth module.
 */
final readonly class ThrottleRequests
{
    public const DEFAULT_SCOPE = 'default';

    /**
     * @param  array{authenticated: int, unauthenticated: int, unauthenticated_auth_endpoints: int}  $limits
     */
    public function __construct(
        private CacheRepository $store,
        private Clock $clock,
        private CorrelationContext $correlation,
        private array $limits,
        private int $windowSeconds,
        private string $authScope,
    ) {}

    public function handle(Request $request, Closure $next, string $scope = self::DEFAULT_SCOPE): Response
    {
        $limit = $this->limitFor($scope);
        $key = sprintf('throttle:%s:%s', $scope, $this->subject($request));
        $windowKey = $key.':window';
        $now = $this->clock->nowUtc()->getTimestamp();
        $ttl = $this->windowSeconds + 1;

        // `add` is the atomic "set if absent", so concurrent requests opening the
        // same window agree on one end instant.
        if ($this->store->add($windowKey, $now + $this->windowSeconds, $ttl)) {
            $this->store->put($key, 0, $ttl);
        }

        $windowEndsAt = $this->windowEndsAt($windowKey, $now);
        $hits = $this->hit($key, $ttl);
        $retryAfter = max(1, $windowEndsAt - $now);

        if ($hits > $limit) {
            return $this->rejection($limit, $retryAfter);
        }

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $limit - $hits));

        return $response;
    }

    private function limitFor(string $scope): int
    {
        if ($this->correlation->actorId() !== null) {
            return $this->limits['authenticated'];
        }

        return $scope === $this->authScope
            ? $this->limits['unauthenticated_auth_endpoints']
            : $this->limits['unauthenticated'];
    }

    /**
     * The authenticated Platform user, or the client address when no Platform
     * user is established.
     */
    private function subject(Request $request): string
    {
        $actorId = $this->correlation->actorId();

        if ($actorId !== null) {
            return 'user:'.$actorId;
        }

        return 'client:'.($request->ip() ?? 'unknown');
    }

    private function windowEndsAt(string $windowKey, int $now): int
    {
        $stored = $this->store->get($windowKey);

        return is_numeric($stored) ? (int) $stored : $now + $this->windowSeconds;
    }

    /**
     * Records one request against the counter and returns the resulting count.
     */
    private function hit(string $key, int $ttl): int
    {
        $hits = $this->store->increment($key);

        if (! is_int($hits) || $hits < 1) {
            // The store could not increment (an absent key on a driver that does
            // not create one); open the counter explicitly.
            $this->store->put($key, 1, $ttl);

            return 1;
        }

        if ($hits === 1) {
            // An increment that created the key may have left it without a
            // time-to-live; restate the value with the window length.
            $this->store->put($key, 1, $ttl);
        }

        return $hits;
    }

    private function rejection(int $limit, int $retryAfter): JsonResponse
    {
        $error = ApiError::of(
            ErrorCatalogue::RATE_LIMIT_EXCEEDED,
            'Too many requests. Retry after the period named in the Retry-After header.',
        );

        return new JsonResponse(
            Envelope::error($error),
            ErrorCatalogue::statusFor(ErrorCatalogue::RATE_LIMIT_EXCEEDED),
            [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $limit,
                'X-RateLimit-Remaining' => '0',
            ],
        );
    }
}
