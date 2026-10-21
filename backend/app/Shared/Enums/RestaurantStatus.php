<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * The four Restaurant_Status values (Requirement 59). Mirrors the constants on
 * App\Modules\Catalog\Domain\RestaurantStatus, which remains the authority on
 * permitted transitions; this enum is the shared vocabulary for the value and
 * its label on admin and API surfaces.
 */
enum RestaurantStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Pending review',
            self::Published => 'Published',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * Whether a Restaurant in this status is shown in Customer-facing results
     * (Requirement 3.1, 59.8): only a published Restaurant is discoverable.
     */
    public function isDiscoverable(): bool
    {
        return $this === self::Published;
    }
}
