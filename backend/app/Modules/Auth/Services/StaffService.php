<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Account\Boundary\AccountProvisioning;
use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Auth\Models\User;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ConflictException;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Enums\UserRole;
use App\Shared\Support\Clock;
use App\Shared\Support\IdGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Restaurant_Staff management for a Restaurant_Operator (Requirement 1, 20). The
 * operator invites staff into its own Restaurant; a staff member is a
 * `restaurant_staff` user linked to the operator's restaurant_id and branch_id.
 * All reads and writes are scoped to the acting operator's Restaurant, so one
 * operator can never see or touch another restaurant's staff.
 */
final class StaffService
{
    /** The most Restaurant_Staff one Restaurant may have (demo cap). */
    private const STAFF_LIMIT = 20;

    public function __construct(
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
        private readonly AccountProvisioning $accountProvisioning,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(ActorContext $actor): array
    {
        $restaurantId = $this->operatorRestaurant($actor);

        return User::query()
            ->where('role', UserRole::RestaurantStaff->value)
            ->where('restaurant_id', $restaurantId)
            ->orderBy('display_name')
            ->get()
            ->map(fn (User $u): array => $this->present($u))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function invite(ActorContext $actor, array $input): array
    {
        $restaurantId = $this->operatorRestaurant($actor);
        $operator = User::query()->find($actor->userId);
        $branchId = $operator?->branch_id;

        $emailLower = mb_strtolower(trim((string) $input['email']));

        if (User::query()->where('email_lower', $emailLower)->exists()) {
            throw ValidationException::because('email_already_registered', 'That email address is already registered.');
        }

        $count = User::query()
            ->where('role', UserRole::RestaurantStaff->value)
            ->where('restaurant_id', $restaurantId)
            ->count();

        if ($count >= self::STAFF_LIMIT) {
            throw ConflictException::because('staff_limit_reached', 'This restaurant has reached its staff limit.');
        }

        $passwordHash = Hash::make((string) $input['password']);

        $user = DB::transaction(function () use ($input, $emailLower, $passwordHash, $restaurantId, $branchId): User {
            $user = new User([
                'id' => $this->ids->ulid(),
                'email' => (string) $input['email'],
                'email_lower' => $emailLower,
                'password_hash' => $passwordHash,
                'display_name' => (string) $input['display_name'],
                'role' => UserRole::RestaurantStaff->value,
                'account_status' => 'active',
                'restaurant_id' => $restaurantId,
                'branch_id' => $branchId,
            ]);
            $user->save();

            $this->accountProvisioning->provisionCustomerProfile($user->id);

            return $user;
        });

        return $this->present($user);
    }

    public function remove(ActorContext $actor, string $staffId): void
    {
        $restaurantId = $this->operatorRestaurant($actor);

        $staff = User::query()
            ->where('id', $staffId)
            ->where('role', UserRole::RestaurantStaff->value)
            ->where('restaurant_id', $restaurantId)
            ->first();

        if ($staff === null) {
            // Non-disclosing: a staff member of another restaurant reads as not found.
            throw AuthorizationException::notFound('restaurant_not_found', 'No staff member matches that identifier.');
        }

        $staff->delete();
    }

    /**
     * The acting operator's own Restaurant, or a forbidden rejection when the
     * actor is not an operator bound to a Restaurant.
     */
    private function operatorRestaurant(ActorContext $actor): string
    {
        if (! $actor->hasRole('restaurant_operator') || $actor->restaurantId === null) {
            throw AuthorizationException::forbidden();
        }

        return $actor->restaurantId;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'account_status' => $user->account_status,
        ];
    }
}
