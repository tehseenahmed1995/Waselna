<?php

declare(strict_types=1);

namespace App\Shared\Http\Health;

/**
 * The reachability of one dependency the health endpoint checks
 * (Requirements 27.1, 27.2).
 *
 * Only the dependency name and a reachable flag are carried: the health body
 * names an unreachable dependency but never reproduces the underlying driver
 * message, which can quote a host, a user, or a connection string
 * (Requirement 27.7). The diagnostic detail goes to the Observability_Stack
 * instead.
 */
final readonly class DependencyStatus
{
    public const string REACHABLE = 'reachable';

    public const string UNREACHABLE = 'unreachable';

    private function __construct(
        public string $name,
        public bool $reachable,
    ) {}

    public static function reachable(string $name): self
    {
        return new self($name, true);
    }

    public static function unreachable(string $name): self
    {
        return new self($name, false);
    }

    public function state(): string
    {
        return $this->reachable ? self::REACHABLE : self::UNREACHABLE;
    }
}
