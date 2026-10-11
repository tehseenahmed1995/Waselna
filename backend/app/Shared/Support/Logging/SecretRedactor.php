<?php

declare(strict_types=1);

namespace App\Shared\Support\Logging;

use DateTimeInterface;
use JsonSerializable;
use Throwable;

/**
 * Removes passwords, Access_Tokens, Refresh_Tokens, and payment instrument data
 * from anything about to be written to a log entry (Requirement 27.7).
 *
 * Two rules, because Property 62 states the guarantee over secrets carried "in
 * bodies, headers, or query strings" rather than over a fixed set of log calls:
 *
 *  1. Key-based. A value whose key names a secret is replaced wholesale, however
 *     deeply it is nested. `password`, `password_confirmation`, `access_token`,
 *     `refresh_token`, `authorization`, `card_number`, `cvv`, and their
 *     case-and-separator variants all match.
 *  2. Literal-based. The caller supplies the secret values the current request
 *     actually carried, and every occurrence of each is replaced inside every
 *     string of the entry — message included. This is what keeps a secret out of
 *     a log line that happens to interpolate it into free text or into an
 *     exception message.
 *
 * Framework-free and stateless, so a queued Job, a console command, and the HTTP
 * log channel all share one redaction rule.
 */
final class SecretRedactor
{
    public const PLACEHOLDER = '[redacted]';

    /**
     * Compared against the key with its separators removed and its case folded,
     * so `card_number`, `cardNumber`, and `CARD-NUMBER` all match `cardnumber`.
     * A key that *contains* one of these is a match too, which is what catches
     * `user_password` and `stripe_client_secret`.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'passwd',
        'secret',
        'token',
        'authorization',
        'apikey',
        'privatekey',
        'cardnumber',
        'pan',
        'cvv',
        'cvc',
        'securitycode',
        'expmonth',
        'expyear',
        'cardexpiry',
    ];

    /**
     * A literal shorter than this is not replaced: a 3-character "secret" would
     * shred unrelated text, and nothing the Platform treats as a secret is that
     * short.
     */
    private const MIN_LITERAL_LENGTH = 6;

    /**
     * True when $key names a value that never belongs in a log entry.
     */
    public function isSensitiveKey(string $key): bool
    {
        $normalised = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));

        if ($normalised === '') {
            return false;
        }

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($normalised, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redacts a whole log context: arrays recursively, objects by their public
     * shape, Throwables by class, message, location, and stack trace.
     *
     * @param  list<string>  $literals  secret values the current request carried
     */
    public function redact(mixed $value, array $literals = []): mixed
    {
        return $this->walk($value, $this->usableLiterals($literals), 0);
    }

    /**
     * Redacts a single string, used for the log message itself.
     *
     * @param  list<string>  $literals
     */
    public function redactText(string $text, array $literals = []): string
    {
        return $this->replaceLiterals($text, $this->usableLiterals($literals));
    }

    /**
     * @param  list<string>  $literals
     */
    private function walk(mixed $value, array $literals, int $depth): mixed
    {
        if ($depth > 16) {
            // Deeper than any log context the Platform composes; stopping here
            // keeps a cyclic structure from becoming an infinite entry.
            return '[truncated]';
        }

        if (is_string($value)) {
            return $this->replaceLiterals($value, $literals);
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $key => $item) {
                $redacted[$key] = is_string($key) && $this->isSensitiveKey($key)
                    ? self::PLACEHOLDER
                    : $this->walk($item, $literals, $depth + 1);
            }

            return $redacted;
        }

        if ($value instanceof Throwable) {
            return $this->walk($this->describe($value), $literals, $depth + 1);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof JsonSerializable) {
            return $this->walk($value->jsonSerialize(), $literals, $depth + 1);
        }

        if (is_object($value)) {
            return $this->walk(get_object_vars($value), $literals, $depth + 1);
        }

        // A resource or a closure: name the type rather than attempt an encoding.
        return '['.get_debug_type($value).']';
    }

    /**
     * The stack trace of Requirement 27.9, recorded in the Observability_Stack
     * and never in a response body.
     *
     * @return array<string, mixed>
     */
    private function describe(Throwable $throwable): array
    {
        return [
            'class' => $throwable::class,
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace' => $throwable->getTraceAsString(),
            'previous' => $throwable->getPrevious() === null
                ? null
                : $this->describe($throwable->getPrevious()),
        ];
    }

    /**
     * @param  list<string>  $literals
     * @return list<string>
     */
    private function usableLiterals(array $literals): array
    {
        $usable = [];

        foreach ($literals as $literal) {
            if (strlen($literal) >= self::MIN_LITERAL_LENGTH) {
                $usable[$literal] = $literal;
            }
        }

        // Longest first, so a token is replaced before a prefix of it is.
        $usable = array_values($usable);
        usort($usable, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $usable;
    }

    /**
     * @param  list<string>  $literals
     */
    private function replaceLiterals(string $text, array $literals): string
    {
        if ($literals === []) {
            return $text;
        }

        return str_replace($literals, self::PLACEHOLDER, $text);
    }
}
