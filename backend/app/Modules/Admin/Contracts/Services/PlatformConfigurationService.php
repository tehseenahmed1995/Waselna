<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts\Services;

use App\Modules\Admin\Boundary\PlatformConfigurationReader;
use App\Modules\Admin\Domain\PlatformConfigurationView;
use App\Modules\Admin\Domain\UpdateConfigurationCommand;

/**
 * The Admin module's Platform_Configuration write Service_Interface
 * (Requirements 37.9, 37.10).
 *
 * One Service_Interface seam over one implementation: an Administrator surface
 * reads the current configuration and submits an update, and the implementation
 * validates every value against the Requirement 37.10 ranges before it persists.
 * The read-only {@see PlatformConfigurationReader}
 * boundary is a second seam onto the same object for the modules that only
 * consume configuration.
 */
interface PlatformConfigurationService
{
    /**
     * The current Platform_Configuration snapshot, or the Platform defaults when
     * no row has been written yet (Requirement 37.9).
     */
    public function current(): PlatformConfigurationView;

    /**
     * Persists the submitted values and returns the resulting configuration
     * (Requirement 37.9). The write is rejected with `validation_failed` and
     * changes nothing when a value falls outside the Requirement 37.10 ranges.
     */
    public function update(UpdateConfigurationCommand $command): PlatformConfigurationView;
}
