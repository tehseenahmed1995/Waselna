<?php

declare(strict_types=1);

namespace App\Modules\Account\Boundary;

/**
 * Module_Boundary the Account module exposes so the Auth module can provision a
 * newly created Platform user's Account-owned records as part of registration
 * (Requirements 1.1, 16.12).
 *
 * The `users` row belongs to Auth, but the `user_profiles` row and the ten
 * `notification_preferences` rows belong to Account. Registration must create
 * all three inside one transaction (design "Transaction boundaries":
 * insert `users` → insert `user_profiles` → insert the ten
 * `notification_preferences` rows). Auth therefore opens the transaction, writes
 * its own `users` row, and reaches Account's tables through this boundary rather
 * than through Account's SQL, which keeps the module-ownership rule of
 * Requirement 22 intact.
 *
 * The call participates in the caller's open transaction and performs no commit
 * of its own: a failure writing the profile or any preference row propagates and
 * rolls the whole registration back, so no partial Platform user is ever left
 * behind.
 */
interface AccountProvisioning
{
    /**
     * Creates the Account-owned records for a newly registered Customer: the
     * single `user_profiles` row and one `notification_preferences` row per
     * Notification_Category and channel pair, each set to the default held in
     * Platform_Configuration (Requirements 1.1, 16.12).
     *
     * Must be invoked inside an already-open database transaction so the writes
     * commit or roll back together with the owning `users` row.
     */
    public function provisionCustomerProfile(string $userId): void;
}
