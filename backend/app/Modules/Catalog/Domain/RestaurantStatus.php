<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * The Restaurant_Status value set and the one place that decides whether a
 * requested status change is a permitted transition (Requirements 59.3, 59.6).
 *
 * Framework-free: this type touches no database, no request, and no container,
 * so the lifecycle service and the property suite can exercise the full
 * transition table with no infrastructure. The permitted edges are held once, as
 * data, exactly as the design's "Restaurant status lifecycle" diagram draws
 * them:
 *
 *   draft           -> pending_review
 *   pending_review  -> published
 *   pending_review  -> draft
 *   published       -> suspended
 *   suspended       -> published
 *
 * Every other ordered pair — including a status equal to itself and any pair
 * touching an unknown value — is not a permitted transition, which is what lets
 * the service reject it with `invalid_status_transition` leaving the stored
 * status unchanged (Requirement 59.6).
 */
final class RestaurantStatus
{
    public const DRAFT = 'draft';

    public const PENDING_REVIEW = 'pending_review';

    public const PUBLISHED = 'published';

    public const SUSPENDED = 'suspended';

    /**
     * The four permitted Restaurant_Status values (design "Enumerated value
     * sets"). Order is immaterial; membership is all this list is read for.
     *
     * @var list<string>
     */
    public const ALL = [
        self::DRAFT,
        self::PENDING_REVIEW,
        self::PUBLISHED,
        self::SUSPENDED,
    ];

    /**
     * The exact set of permitted transitions as `from` to the set of reachable
     * `to` statuses (Requirement 59.3). A `from` absent from this map, or a `to`
     * absent from its list, is not permitted.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::DRAFT => [self::PENDING_REVIEW],
        self::PENDING_REVIEW => [self::PUBLISHED, self::DRAFT],
        self::PUBLISHED => [self::SUSPENDED],
        self::SUSPENDED => [self::PUBLISHED],
    ];

    /**
     * True exactly when `$from` is a permitted Restaurant_Status and `$to` is one
     * of the statuses reachable from it in a single step (Requirement 59.3).
     *
     * A transition to the same status, a transition from or to an unknown value,
     * and any ordered pair outside the five permitted edges all return false, so
     * the caller rejects them uniformly with `invalid_status_transition`
     * (Requirement 59.6).
     */
    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * True when `$status` is one of the four permitted Restaurant_Status values.
     */
    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /**
     * The statuses reachable from `$from` in a single permitted step, empty for a
     * terminal or unknown status. Exposed so a test can assert the table without
     * reaching into the private constant.
     *
     * @return list<string>
     */
    public static function transitionsFrom(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    /**
     * True when reaching `$to` requires the publication prerequisites of
     * Requirement 59.5 to hold — that is, whenever the target status is
     * `published`, reached either from `pending_review` or from `suspended`.
     */
    public static function requiresPublishPrerequisites(string $to): bool
    {
        return $to === self::PUBLISHED;
    }
}
