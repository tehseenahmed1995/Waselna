<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * The framework-free rule that decides whether a Restaurant may become
 * `published`, naming every unmet condition (Requirement 59.5).
 *
 * A Restaurant is publishable only when all three conditions hold at once:
 *
 *  1. it holds at least one Restaurant_Branch with an active flag of true;
 *  2. it holds at least one Menu_Item with an availability flag of true;
 *  3. it holds at least one Delivery_Zone assigned to an active Restaurant_Branch.
 *
 * The service gathers these three facts from the database into a
 * {@see PublishReadiness} and passes it here; the rule itself stays pure so the
 * property suite can enumerate every combination of the three booleans without a
 * database. {@see unmetConditions()} returns the stable condition names the
 * `restaurant_not_publishable` response lists, in a fixed order, so the response
 * names every unmet condition and only the unmet ones (Requirement 59.5).
 */
final class PublishPrerequisites
{
    /** No active Restaurant_Branch exists (Requirement 59.5). */
    public const NO_ACTIVE_BRANCH = 'no_active_branch';

    /** No available Menu_Item exists (Requirement 59.5). */
    public const NO_AVAILABLE_MENU_ITEM = 'no_available_menu_item';

    /** No Delivery_Zone is assigned to an active Restaurant_Branch (Requirement 59.5). */
    public const NO_ZONE_ON_ACTIVE_BRANCH = 'no_zone_on_active_branch';

    /**
     * The condition names that are not satisfied by `$readiness`, in the fixed
     * order active-branch, available-item, zone-on-active-branch. An empty list
     * means every prerequisite holds and the Restaurant may be published.
     *
     * @return list<string>
     */
    public static function unmetConditions(PublishReadiness $readiness): array
    {
        $unmet = [];

        if (! $readiness->hasActiveBranch) {
            $unmet[] = self::NO_ACTIVE_BRANCH;
        }

        if (! $readiness->hasAvailableMenuItem) {
            $unmet[] = self::NO_AVAILABLE_MENU_ITEM;
        }

        if (! $readiness->hasZoneOnActiveBranch) {
            $unmet[] = self::NO_ZONE_ON_ACTIVE_BRANCH;
        }

        return $unmet;
    }

    /**
     * True when `$readiness` satisfies every publication prerequisite.
     */
    public static function isPublishable(PublishReadiness $readiness): bool
    {
        return self::unmetConditions($readiness) === [];
    }
}
