<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pipeline position 3. Restricts cross-origin access to the configured
 * Web_Client origins (Requirement 28.4).
 *
 * The rule Property 64 states is an exact one: cross-origin access is granted
 * exactly when the request origin is in the configured list. That grant is
 * expressed by the presence of `Access-Control-Allow-Origin`; an origin outside
 * the list receives a response carrying no cross-origin headers at all, so a
 * browser refuses to hand it to the calling script.
 *
 * A preflight `OPTIONS` request is answered here with 204 and never reaches a
 * route. Same-origin requests (no `Origin` header) pass through untouched.
 */
final readonly class Cors
{
    /**
     * @param  list<string>  $allowedOrigins  absolute origins, optionally with "*" wildcard segments
     * @param  list<string>  $allowedMethods
     * @param  list<string>  $allowedHeaders
     * @param  list<string>  $exposedHeaders
     */
    public function __construct(
        private array $allowedOrigins,
        private array $allowedMethods,
        private array $allowedHeaders,
        private array $exposedHeaders,
        private int $maxAge,
        private bool $supportsCredentials,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $isPreflight = $request->getMethod() === 'OPTIONS'
            && $request->headers->has('Access-Control-Request-Method');

        if ($isPreflight) {
            $response = new HttpResponse('', HttpResponse::HTTP_NO_CONTENT);

            if ($this->isAllowed($origin)) {
                $this->grant($response, (string) $origin);
                $this->describePreflight($response);
            }

            return $response;
        }

        /** @var Response $response */
        $response = $next($request);

        if ($this->isAllowed($origin)) {
            $this->grant($response, (string) $origin);
        }

        return $response;
    }

    private function isAllowed(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return false;
        }

        foreach ($this->allowedOrigins as $allowed) {
            if ($allowed === $origin) {
                return true;
            }

            if (str_contains($allowed, '*') && Str::is($allowed, $origin)) {
                return true;
            }
        }

        return false;
    }

    private function grant(Response $response, string $origin): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);

        // The response depends on the request origin, so caches must key on it.
        $response->headers->set('Vary', 'Origin');

        if ($this->exposedHeaders !== []) {
            $response->headers->set('Access-Control-Expose-Headers', implode(', ', $this->exposedHeaders));
        }

        if ($this->supportsCredentials) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }
    }

    private function describePreflight(Response $response): void
    {
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods));
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));
        $response->headers->set('Access-Control-Max-Age', (string) $this->maxAge);
    }
}
