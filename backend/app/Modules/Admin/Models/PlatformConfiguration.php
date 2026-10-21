<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Admin\Boundary\PlatformConfigurationReader;
use App\Modules\Admin\Domain\PlatformConfigurationView;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The single Platform_Configuration row (Requirements 37.9, 37.10).
 *
 * Platform_Configuration is the Administrator-editable set of Platform-wide
 * settings — the Service_Fee percentage and absolute bounds, the initial and
 * maximum Offer_Radii, the Order rejection reason list, and the per-category
 * Notification defaults. The Admin module owns this table because Requirement
 * 37.9 makes Administrators its only writers; every other module reads it
 * through the {@see PlatformConfigurationReader}
 * boundary rather than touching this model (design "Admin_Module").
 *
 * The table holds at most one row ("single-row configuration semantics"): reads
 * take the first row and fall back to {@see
 * \App\Modules\Admin\Domain\PlatformConfigurationDefaults} when none exists, and
 * writes update that one row. The model is therefore internal to the Admin
 * module and never leaves it; consumers see the framework-free
 * {@see PlatformConfigurationView} snapshot instead.
 *
 * Column names follow the existing `platform_configuration` migration
 * (`2024_01_07_000001_create_remaining_tables`): fee percentage bounds in basis
 * points, Offer_Radii in whole kilometres, and the reason/notification lists as
 * JSON. The design's data-model table uses richer names; the running schema is
 * authoritative here and the typed accessors on the reader hide the difference.
 *
 * @property string $id
 * @property int $service_fee_percentage_min_bp
 * @property int $service_fee_percentage_max_bp
 * @property int $delivery_fee_min_minor
 * @property int $delivery_fee_max_minor
 * @property int $offer_radius_km_default
 * @property int $offer_radius_km_max
 * @property ?array<int, string> $rejection_reasons
 * @property ?array<string, mixed> $notification_defaults
 * @property ?Carbon $updated_at
 */
final class PlatformConfiguration extends Model
{
    protected $table = 'platform_configuration';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The row carries only an `updated_at` column, so Eloquent's default
     * `created_at` maintenance is switched off and timestamp handling is scoped
     * to the one column the table has.
     */
    public const CREATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_fee_percentage_min_bp' => 'integer',
            'service_fee_percentage_max_bp' => 'integer',
            'delivery_fee_min_minor' => 'integer',
            'delivery_fee_max_minor' => 'integer',
            'offer_radius_km_default' => 'integer',
            'offer_radius_km_max' => 'integer',
            'rejection_reasons' => 'array',
            'notification_defaults' => 'array',
            'updated_at' => 'datetime',
        ];
    }
}
