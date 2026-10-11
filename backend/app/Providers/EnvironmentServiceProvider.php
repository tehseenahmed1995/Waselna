<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\Support\Configuration\ProcessEnvironment;
use App\Shared\Support\Configuration\RequiredConfiguration;
use Illuminate\Support\ServiceProvider;

/**
 * The boot-time configuration check.
 *
 * Registered first in bootstrap/providers.php so the check runs before any
 * other binding exists. An absent or empty required environment variable throws
 * `MissingConfiguration`, which terminates startup with a message naming every
 * offending variable and its grouping heading (Requirements 24.9, 44.8).
 */
class EnvironmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        RequiredConfiguration::assert(
            ProcessEnvironment::values(RequiredConfiguration::names()),
        );
    }
}
