<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pipeline position 2. Sets `Strict-Transport-Security`,
 * `X-Content-Type-Options`, `X-Frame-Options`, and `Content-Security-Policy` on
 * every response (Requirement 28.9).
 *
 * The headers are set unconditionally, including on error responses, because
 * Property 59 asserts their presence on every response rather than on successful
 * ones only. A header a response already carries is left alone, so a later
 * endpoint may tighten its own policy without this middleware overwriting it.
 */
final readonly class SecurityHeaders
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(private array $headers) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        foreach ($this->headers as $name => $value) {
            if ($value !== '' && ! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
