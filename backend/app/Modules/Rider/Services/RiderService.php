<?php

declare(strict_types=1);

namespace App\Modules\Rider\Services;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Dispatch\Models\DeliveryJob;
use App\Modules\Rider\Models\CourierProfile;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ConflictException;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\Clock;

/**
 * Courier profile and Rider_Status (Requirement 35, 58). A Courier must complete
 * their profile (vehicle type + phone) before going online; they cannot go
 * offline while a delivery is in progress.
 */
final class RiderService
{
    private const VEHICLE_TYPES = ['bicycle', 'motorcycle', 'car'];

    public function __construct(private readonly Clock $clock) {}

    /**
     * @return array<string, mixed>
     */
    public function profile(ActorContext $actor): array
    {
        return $this->present($this->ensureProfile($actor->userId));
    }

    /**
     * @param  array{vehicle_type?: string, phone?: string}  $input
     * @return array<string, mixed>
     */
    public function updateProfile(ActorContext $actor, array $input): array
    {
        $profile = $this->ensureProfile($actor->userId);

        if (isset($input['vehicle_type'])) {
            if (! in_array($input['vehicle_type'], self::VEHICLE_TYPES, true)) {
                throw ValidationException::forField('vehicle_type', 'in:bicycle,motorcycle,car');
            }
            $profile->vehicle_type = $input['vehicle_type'];
        }

        if (isset($input['phone'])) {
            $profile->phone = $input['phone'];
        }

        $profile->updated_at = $this->clock->nowUtc();
        $profile->save();

        return $this->present($profile);
    }

    /**
     * @return array<string, mixed>
     */
    public function goOnline(ActorContext $actor): array
    {
        $profile = $this->ensureProfile($actor->userId);

        if ($profile->vehicle_type === null || $profile->phone === null || trim((string) $profile->phone) === '') {
            throw ConflictException::because('courier_profile_incomplete', 'Set a vehicle type and phone before going online.');
        }

        $profile->rider_status = 'online';
        $profile->updated_at = $this->clock->nowUtc();
        $profile->save();

        return $this->present($profile);
    }

    /**
     * @return array<string, mixed>
     */
    public function goOffline(ActorContext $actor): array
    {
        $profile = $this->ensureProfile($actor->userId);

        $hasActive = DeliveryJob::query()
            ->where('assigned_courier_id', $actor->userId)
            ->whereIn('status', ['accepted', 'pickup', 'picked_up', 'in_transit'])
            ->exists();

        if ($hasActive) {
            throw ConflictException::because('active_delivery_in_progress', 'Finish the active delivery before going offline.');
        }

        $profile->rider_status = 'offline';
        $profile->updated_at = $this->clock->nowUtc();
        $profile->save();

        return $this->present($profile);
    }

    public function isOnline(string $courierId): bool
    {
        return CourierProfile::query()
            ->where('user_id', $courierId)
            ->where('rider_status', 'online')
            ->exists();
    }

    private function ensureProfile(string $userId): CourierProfile
    {
        /** @var CourierProfile|null $profile */
        $profile = CourierProfile::query()->find($userId);

        if ($profile !== null) {
            return $profile;
        }

        $now = $this->clock->nowUtc();
        $profile = new CourierProfile([
            'user_id' => $userId,
            'rider_status' => 'offline',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $profile->save();

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CourierProfile $profile): array
    {
        return [
            'vehicle_type' => $profile->vehicle_type,
            'phone' => $profile->phone,
            'rider_status' => $profile->rider_status,
        ];
    }
}
