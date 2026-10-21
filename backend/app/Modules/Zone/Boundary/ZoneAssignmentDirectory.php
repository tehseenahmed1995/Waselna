<?php

declare(strict_types=1);

namespace App\Modules\Zone\Boundary;

/**
 * The Module_Boundary the Zone module exposes for the Delivery_Zone assignment
 * the Catalog module performs but does not own (design Zone notes; Requirements
 * 22.8, 32.11).
 *
 * A Restaurant_Operator assigns Delivery_Zones of its Restaurant to one of that
 * Restaurant's Restaurant_Branches (Requirement 32.11). The membership — the
 * `delivery_zone_branch` rows — lives in the Zone module's tables, so the
 * Catalog module reaches it through this boundary rather than through the Zone
 * module's `delivery_zones` and `delivery_zone_branch` tables directly. That
 * keeps the Catalog module free of a direct Eloquent dependency on a Zone table
 * (Requirement 22.8, Deptrac ruleset) and keeps the single-transaction
 * replacement of a Restaurant_Branch's assignments inside the owning module.
 *
 * The Catalog module stays the owner of the authorization and limit rules of
 * Requirement 32: it is the Catalog service that locks the parent Restaurant,
 * decides whether the actor owns it, and rejects more than 20 submitted zones.
 * This boundary answers only the two questions the Catalog service cannot answer
 * without reading a Zone table — which zones a Restaurant owns, so an unknown
 * zone can be rejected with `validation_failed` (Requirement 32.12) — and
 * performs the assignment write itself.
 */
interface ZoneAssignmentDirectory
{
    /**
     * The identifiers of every Delivery_Zone that belongs to $restaurantId, in
     * ascending identifier order. The Catalog service compares a submitted
     * assignment against this set and rejects any zone the Restaurant does not
     * own with `validation_failed` (Requirement 32.12).
     *
     * @return list<string>
     */
    public function zoneIdsForRestaurant(string $restaurantId): array;

    /**
     * The identifiers of the Delivery_Zones currently assigned to $branchId, in
     * ascending identifier order, or an empty list where none are assigned
     * (Requirement 32.11). The Catalog module reads this for a Restaurant_Branch
     * projection so it can report the Serviceable_Area composition without
     * reaching into a Zone table.
     *
     * @return list<string>
     */
    public function zoneIdsForBranch(string $branchId): array;

    /**
     * Replaces the complete set of Delivery_Zones assigned to $branchId with
     * $zoneIds in one transaction, so the assignment is all-or-nothing and the
     * Restaurant_Branch's Serviceable_Area becomes exactly the union of
     * $zoneIds (Requirements 32.11, 40.8). Passing an empty list clears the
     * assignment, leaving the Restaurant_Branch's Serviceable_Area empty
     * (Requirement 32.11).
     *
     * The caller is expected to have already validated, under a lock on the
     * parent Restaurant, that every identifier in $zoneIds belongs to the
     * Restaurant and that the list holds at most 20 entries.
     *
     * @param  list<string>  $zoneIds
     */
    public function replaceBranchAssignments(string $branchId, array $zoneIds): void;

    /**
     * True when at least one Delivery_Zone is assigned to at least one active
     * Restaurant_Branch of $restaurantId. This answers the third publication
     * prerequisite of Requirement 59.5 — "at least one Delivery_Zone assigned to
     * a Restaurant_Branch with an active flag of true" — without the Catalog
     * module reading the `delivery_zone_branch` pivot it does not own. The
     * active-branch join lives here because the pivot does; Catalog supplies
     * only the Restaurant identifier.
     */
    public function anyZoneOnActiveBranch(string $restaurantId): bool;
}
