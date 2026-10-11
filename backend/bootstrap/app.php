<?php

declare(strict_types=1);

use App\Shared\Domain\Exceptions\DomainException;
use App\Shared\Http\ApiExceptionRenderer;
use App\Shared\Http\Middleware\AssignCorrelationIdentifier;
use App\Shared\Http\Middleware\AuthenticateBearer;
use App\Shared\Http\Middleware\AuthorizeRole;
use App\Shared\Http\Middleware\Cors;
use App\Shared\Http\Middleware\JsonApiResponse;
use App\Shared\Http\Middleware\SecurityHeaders;
use App\Shared\Http\Middleware\ThrottleRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The cross-cutting request pipeline, in the order the design fixes.
        // Positions 4 and 5 are placeholders until the Auth module lands; both
        // are no-ops for a request that names no Role and carries no credentials.
        $middleware->group('api', [
            AssignCorrelationIdentifier::class,          // 1 — Requirements 27.3–27.5
            SecurityHeaders::class,                      // 2 — Requirement 28.9
            Cors::class,                                 // 3 — Requirement 28.4
            AuthenticateBearer::class.':optional',       // 4 — Requirements 1.11, 2.2
            AuthorizeRole::class,                        // 5 — Requirements 2.3, 2.10
            ThrottleRequests::class,                     // 6 — Requirement 28.3
            JsonApiResponse::class,                      // 7 — Requirements 20.2–20.4
        ]);

        // Requirement 28.4 is served by the Cors middleware at position 3, which
        // grants access to exactly the configured Web_Client origins. The
        // framework's own CORS middleware would answer alongside it, so it is
        // removed rather than configured twice.
        $middleware->remove([HandleCors::class]);

        // A browser preflight is an OPTIONS request to a route registered only
        // for POST/GET, which Laravel answers with an implicit handler that never
        // enters the `api` group — so the group copy of Cors above never sees it
        // and the browser blocks the real request. Prepending Cors globally lets
        // it answer the preflight (and attach the grant to the real response)
        // before routing, exactly as the framework's own HandleCors did. The
        // middleware is a no-op unless an Origin header is present and allowed,
        // so running it on every request is safe.
        $middleware->prepend(Cors::class);

        // Requirement 28.9 asks for the four security headers on every
        // Web_Client-facing response, including one to a path that matches no
        // route and therefore never enters the `api` group. The middleware leaves
        // a header the response already carries alone, so the copy at position 2
        // remains the one that sets them for a routed request.
        $middleware->append(SecurityHeaders::class);

        // Per-route refinements of positions 4, 5, and 6. A route demanding
        // credentials adds `auth.bearer:required`; a route restricted by Role adds
        // `role:administrator`; an endpoint group with its own allowance adds
        // `throttle.scope:auth`, whose stricter counter rejects before the group
        // allowance is reached.
        $middleware->alias([
            'auth.bearer' => AuthenticateBearer::class,
            'role' => AuthorizeRole::class,
            'throttle.scope' => ThrottleRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A DomainException is a documented outcome of an endpoint, not a fault;
        // rendering it is enough (Requirements 20.3, 20.4). Everything else is
        // reported, which is where the stack trace of Requirement 27.9 is
        // recorded.
        $exceptions->dontReport([DomainException::class]);

        $exceptions->render(function (Throwable $exception, Request $request): ?Response {
            $renderer = app(ApiExceptionRenderer::class);

            return $renderer->handles($request)
                ? $renderer->render($exception, $request)
                : null;
        });
    })->create();
