<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Modules\Auth\Services\AuthenticationService;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Support\Correlation\CorrelationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pipeline position 4. Resolves the opaque Access_Token presented in the
 * `Authorization` header.
 *
 * PLACEHOLDER. Token resolution needs the `access_tokens` table and the
 * AuthenticationService, which arrive with the Auth module (tasks 3.1 and 3.2).
 * What is implemented here is the part that needs neither: the header grammar.
 *
 *  - No `Authorization` header, mode `optional`: the request continues as an
 *    unauthenticated caller. Public reads (Restaurant discovery, Menu) need this.
 *  - No `Authorization` header, mode `required`: `401 unauthenticated`
 *    (Requirement 2.2).
 *  - A header that is not exactly `Bearer <token>`: `401 unauthenticated`,
 *    because malformed credentials are indistinguishable from absent ones
 *    (Requirement 2.2).
 *  - A well-formed `Bearer <token>`: the raw token is recorded on the request as
 *    the `access_token` attribute and the caller stays unauthenticated for now.
 *
 * Task 3.2 replaces that last branch with the SHA-256 lookup that yields the
 * ActorContext, `401 invalid_token` for a revoked or expired token
 * (Requirement 1.11), and `403 account_suspended` for a suspended account, and
 * records the acting Platform user identifier on the CorrelationContext for
 * Requirement 27.3. The identifier hand-off is wired already so the log shape
 * does not change when that task lands.
 */
final readonly class AuthenticateBearer
{
    public const MODE_OPTIONAL = 'optional';

    public const MODE_REQUIRED = 'required';

    private const SCHEME = 'Bearer ';

    public function __construct(
        private CorrelationContext $correlation,
        private AuthenticationService $auth,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = self::MODE_OPTIONAL): Response
    {
        $header = $request->headers->get('Authorization');

        if ($header === null || trim($header) === '') {
            if ($mode === self::MODE_REQUIRED) {
                throw AuthorizationException::unauthenticated();
            }

            return $next($request);
        }

        $token = $this->presentedToken($header);

        if ($token === null) {
            throw AuthorizationException::unauthenticated();
        }

        // Never logged: the redaction rules of Requirement 27.7 exclude
        // Access_Tokens from every log entry.
        $request->attributes->set('access_token', $token);

        $actor = $this->auth->resolve($token);

        if ($actor === null) {
            throw AuthorizationException::invalidToken();
        }

        if ($actor->isSuspended()) {
            throw AuthorizationException::accountSuspended();
        }

        $request->attributes->set('actor', $actor);
        $this->correlation->identifyActor($actor->userId);

        return $next($request);
    }

    /**
     * The raw token of a well-formed `Bearer <token>` header, or null.
     */
    private function presentedToken(string $header): ?string
    {
        if (! str_starts_with($header, self::SCHEME)) {
            return null;
        }

        $token = substr($header, strlen(self::SCHEME));

        if ($token === '' || trim($token) !== $token || str_contains($token, ' ')) {
            return null;
        }

        return $token;
    }
}
