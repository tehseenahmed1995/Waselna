<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Http\Envelope;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pipeline position 7, the innermost middleware. Wraps a Controller result in the
 * documented envelope (Requirements 20.2, 20.10) and guarantees a JSON content
 * type on every response (Requirement 20.2).
 *
 * The two other duties the design assigns this position — rendering an API_Error
 * and collapsing an unhandled exception to `internal_error` (Requirements 20.3,
 * 20.4, 27.9) — are discharged by ApiExceptionRenderer, which the framework
 * invokes at this same point in the pipeline, so an error body is composed once
 * and in one place. What arrives here is therefore either a Controller result or
 * an already-rendered API_Error.
 *
 * Wrapping is idempotent: a payload that already carries a `data` or an `error`
 * key is passed through unchanged, so a Controller may return a Page envelope or
 * a JsonResource without being wrapped twice.
 */
final readonly class JsonApiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        if ($response->getStatusCode() === Response::HTTP_NO_CONTENT) {
            return $response;
        }

        $payload = $response->getData(true);

        if (! is_array($payload) || $payload === []) {
            return $response;
        }

        if (Envelope::wraps($payload)) {
            return $response;
        }

        $response->setData($this->envelope($payload));

        return $response;
    }

    /**
     * A list becomes a collection envelope, a map becomes a single-resource
     * envelope.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    private function envelope(array $payload): array
    {
        if (array_is_list($payload)) {
            return Envelope::items($payload, count($payload));
        }

        /** @var array<string, mixed> $payload */
        return Envelope::resource($payload);
    }
}
