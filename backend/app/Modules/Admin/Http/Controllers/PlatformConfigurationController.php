<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Contracts\Services\PlatformConfigurationService;
use App\Modules\Admin\Domain\PlatformConfigurationView;
use App\Modules\Admin\Http\Requests\UpdateConfigurationRequest;
use Illuminate\Http\JsonResponse;

/**
 * The Administrator Platform_Configuration surface (Requirements 37.9, 37.10).
 *
 * The Controller holds no rules: it reads the current configuration or hands the
 * Service a command built from the validated request, and renders the resulting
 * {@see PlatformConfigurationView}. Administrator
 * authorization is enforced by the `role:administrator` middleware the route
 * carries, so only an Administrator reaches either action (Requirement 37.9).
 */
final class PlatformConfigurationController
{
    public function __construct(private readonly PlatformConfigurationService $configuration) {}

    public function show(): JsonResponse
    {
        return new JsonResponse(['data' => $this->configuration->current()->toArray()], 200);
    }

    public function update(UpdateConfigurationRequest $request): JsonResponse
    {
        $view = $this->configuration->update($request->toCommand());

        return new JsonResponse(['data' => $view->toArray()], 200);
    }
}
