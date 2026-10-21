<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * The three booleans the publication rule of Requirement 59.5 is evaluated
 * against, gathered from the database by the lifecycle service and handed to
 * {@see PublishPrerequisites}.
 *
 * Keeping the facts in a value object is what lets the rule stay framework-free:
 * the service does the counting (across Catalog's own tables and, for the zone
 * fact, across the Zone boundary seam), this records the answers, and the rule
 * reads them.
 */
final readonly class PublishReadiness
{
    public function __construct(
        public bool $hasActiveBranch,
        public bool $hasAvailableMenuItem,
        public bool $hasZoneOnActiveBranch,
    ) {}
}
