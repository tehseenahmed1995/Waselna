<?php

declare(strict_types=1);

namespace App\Shared\Support\Correlation;

/**
 * The per-request holder of the Correlation_Identifier and the acting Platform
 * user identifier.
 *
 * Bound as a singleton, so `AssignCorrelationIdentifier` writes the identifier
 * once and every later reader — the log processor (Requirement 27.3), the queue
 * payload hook (Requirement 27.5), the broadcast payload, the response header
 * (Requirement 27.4) — reads the same value without threading it through
 * constructor arguments.
 *
 * Framework-free and deliberately mutable: it is the one piece of request state
 * the pipeline shares.
 */
final class CorrelationContext
{
    private ?CorrelationId $correlationId = null;

    private ?string $actorId = null;

    public function assign(CorrelationId $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function has(): bool
    {
        return $this->correlationId !== null;
    }

    public function correlationId(): ?CorrelationId
    {
        return $this->correlationId;
    }

    /**
     * The identifier as a string, or null before the middleware has run (a
     * console command or a queued Job outside a request).
     */
    public function value(): ?string
    {
        return $this->correlationId?->value;
    }

    /**
     * Recorded by `AuthenticateBearer` once the Access_Token resolves, so log
     * entries can name the acting Platform user (Requirement 27.3). Never the
     * token, only the identifier.
     */
    public function identifyActor(?string $actorId): void
    {
        $this->actorId = $actorId;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * Clears both values. A long-running worker calls this between Jobs so no
     * identifier leaks from one unit of work into the next.
     */
    public function forget(): void
    {
        $this->correlationId = null;
        $this->actorId = null;
    }
}
