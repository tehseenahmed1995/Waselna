<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Support\Correlation\CorrelationContext;
use App\Shared\Support\Correlation\CorrelationId;
use App\Shared\Support\IdGenerator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pipeline position 1. Accepts `X-Correlation-Id`, otherwise generates a ULID;
 * binds the value into the log context, the queue Job payload, and the broadcast
 * payload; returns it in the response headers.
 *
 * Requirement 27.4 covers the accept-or-generate and echo behaviour;
 * Requirement 27.5 covers propagation into every queued Job and Realtime_Event
 * of the request. The propagation itself is registered once in
 * AppServiceProvider from the same CorrelationContext this middleware writes, so
 * no producer has to be told the identifier explicitly.
 *
 * This middleware never rejects a request: an unusable header value is replaced
 * by a generated identifier rather than answered with a 4xx.
 */
final readonly class AssignCorrelationIdentifier
{
    public function __construct(
        private CorrelationContext $context,
        private IdGenerator $idGenerator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->headers->get(CorrelationId::HEADER);

        $correlationId = CorrelationId::tryFromString($header)
            ?? CorrelationId::fromString($this->idGenerator->ulid());

        $this->context->assign($correlationId);

        // Readable by Controllers and Form_Requests without resolving the
        // context out of the container.
        $request->attributes->set('correlation_id', $correlationId->value);
        $request->headers->set(CorrelationId::HEADER, $correlationId->value);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(CorrelationId::HEADER, $correlationId->value);

        return $response;
    }
}
