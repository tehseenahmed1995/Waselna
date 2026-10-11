<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Support\Correlation\CorrelationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pipeline position 5. Evaluates the Role recorded in the database on every
 * request, never a Role claim carried in the token (Requirements 2.3, 2.10).
 *
 * PLACEHOLDER. The Role matrix, the ActorContext, and the policy objects that
 * Realtime channel authorization reuses arrive with task 3.6. Until then this
 * middleware only holds the two decisions that do not depend on them, in the
 * order the design's "Rejection ordering" section fixes:
 *
 *  - No Role named by the route: nothing to evaluate, the request continues.
 *  - A Role named by the route with no acting Platform user resolved:
 *    `401 unauthenticated`, because authentication precedes authorization.
 *
 * Task 3.6 adds the third decision — an acting Platform user whose database Role
 * is not among the named ones receives `403 forbidden`, and a suspended account
 * receives `403 account_suspended`.
 */
final readonly class AuthorizeRole
{
    public function __construct(private CorrelationContext $correlation) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if ($roles === []) {
            return $next($request);
        }

        $actor = $request->attributes->get('actor');

        if ($actor === null) {
            throw AuthorizationException::unauthenticated();
        }

        if (! $actor->hasRole(...$roles)) {
            throw AuthorizationException::forbidden();
        }

        return $next($request);
    }
}
