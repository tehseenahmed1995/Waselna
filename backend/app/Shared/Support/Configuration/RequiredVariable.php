<?php

declare(strict_types=1);

namespace App\Shared\Support\Configuration;

/**
 * One environment variable the Delivery_API requires at boot, paired with the
 * grouping heading it belongs to (Requirements 44.3, 44.8).
 */
final readonly class RequiredVariable
{
    public function __construct(
        public string $name,
        public ConfigurationGroup $group,
    ) {}

    /**
     * Is this variable satisfied by the given raw value? An absent value and a
     * value that is empty or whitespace only are both unsatisfied
     * (Requirement 44.8).
     */
    public function isSatisfiedBy(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    /**
     * The variable named together with its grouping heading, as a failed boot
     * message states it.
     */
    public function describe(): string
    {
        return sprintf('%s (grouping heading %s)', $this->name, $this->group->value);
    }
}
