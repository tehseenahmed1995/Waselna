<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Boundary;

/**
 * Module_Boundary the Catalog module exposes so the Auth module can provision a
 * Restaurant and its first Restaurant_Branch when a Restaurant_Operator
 * self-registers (Requirement 1, 32).
 *
 * The `users` row belongs to Auth, but the `restaurants` and
 * `restaurant_branches` rows belong to Catalog. Operator registration must
 * create all of them inside one transaction: Auth opens the transaction, writes
 * its own `users` row, and reaches Catalog's tables through this boundary rather
 * than through Catalog's SQL, which keeps the module-ownership rule of
 * Requirement 22 intact.
 *
 * The call participates in the caller's open transaction and performs no commit
 * of its own: a failure propagates and rolls the whole registration back, so no
 * partial operator/restaurant is ever left behind.
 */
interface RestaurantProvisioning
{
    /**
     * Creates a draft Restaurant with one main Restaurant_Branch for a newly
     * registered Restaurant_Operator and returns the new identifiers so Auth can
     * link them onto the `users` row.
     *
     * Created as a draft (not published) so the operator completes the profile
     * and menu from their dashboard before the Restaurant becomes discoverable.
     *
     * Must be invoked inside an already-open database transaction.
     *
     * @return array{restaurant_id: string, branch_id: string}
     */
    public function provisionOperatorRestaurant(string $restaurantName): array;
}
