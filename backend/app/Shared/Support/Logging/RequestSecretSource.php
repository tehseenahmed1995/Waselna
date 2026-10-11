<?php

declare(strict_types=1);

namespace App\Shared\Support\Logging;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Throwable;

/**
 * Harvests, from the request being served, the values Requirement 27.7 excludes
 * from every log entry: passwords, Access_Tokens, Refresh_Tokens, and payment
 * instrument values, wherever the request carried them — body, query string,
 * route parameter, or header.
 *
 * Read lazily at the moment a log entry is formatted rather than captured by a
 * middleware, so an entry emitted before the pipeline reaches a Controller and an
 * entry emitted while rendering a failure are both covered, and no extra
 * middleware position is needed.
 *
 * Outside an HTTP request (a console command, a Queue_Worker) there is no request
 * to read and the source is empty; key-based redaction still applies.
 */
final readonly class RequestSecretSource implements SecretSource
{
    private const SCHEME = 'Bearer ';

    /**
     * Header names whose whole value is a secret, whatever the header grammar.
     *
     * @var list<string>
     */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'x-api-key',
        'x-refresh-token',
    ];

    public function __construct(
        private Container $container,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @return list<string>
     */
    public function secrets(): array
    {
        $request = $this->currentRequest();

        if ($request === null) {
            return [];
        }

        $secrets = [];

        $this->collect($request->all(), $secrets);
        $this->collect($request->route()?->parameters() ?? [], $secrets);

        foreach (self::SENSITIVE_HEADERS as $header) {
            $value = $request->headers->get($header);

            if ($value === null || $value === '') {
                continue;
            }

            $secrets[] = $value;

            if (str_starts_with($value, self::SCHEME)) {
                // The token on its own, so a log line naming the token without
                // the scheme is covered too.
                $secrets[] = substr($value, strlen(self::SCHEME));
            }
        }

        // Recorded by AuthenticateBearer for the Auth module; a secret by
        // definition.
        $presented = $request->attributes->get('access_token');

        if (is_string($presented) && $presented !== '') {
            $secrets[] = $presented;
        }

        return array_values(array_unique(array_filter(
            $secrets,
            static fn (string $secret): bool => trim($secret) !== '',
        )));
    }

    /**
     * Every value under a key the redactor considers sensitive, at any depth.
     *
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $secrets
     */
    private function collect(array $values, array &$secrets, int $depth = 0): void
    {
        if ($depth > 8) {
            return;
        }

        foreach ($values as $key => $value) {
            $sensitive = is_string($key) && $this->redactor->isSensitiveKey($key);

            if (is_array($value)) {
                $this->collect($value, $secrets, $depth + 1);

                continue;
            }

            if ($sensitive && (is_string($value) || is_int($value))) {
                $secrets[] = (string) $value;
            }
        }
    }

    private function currentRequest(): ?Request
    {
        if (! $this->container->bound('request')) {
            return null;
        }

        try {
            $request = $this->container->get('request');
        } catch (Throwable) {
            return null;
        }

        return $request instanceof Request ? $request : null;
    }
}
