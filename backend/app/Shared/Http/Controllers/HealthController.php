<?php

declare(strict_types=1);

namespace App\Shared\Http\Controllers;

use App\Shared\Http\Envelope;
use App\Shared\Http\Health\HealthProbe;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The health endpoint of the Delivery_API.
 *
 * Answers HTTP 200 with the state of the PostgreSQL connection, the Cache_Store
 * connection, and the queue connection when every one of them answers
 * (Requirement 27.1), and HTTP 503 naming each unreachable dependency otherwise
 * (Requirement 27.2). The Local_Dev_Environment and the Deploy_Pipeline read the
 * same endpoint to decide that a release is up (Requirements 24.2, 25.8).
 *
 * The body is the documented single-resource envelope, so a health response is
 * shaped like every other Delivery_API response (Requirement 20.2).
 */
final readonly class HealthController
{
    public function __construct(private HealthProbe $probe) {}

    public function __invoke(): JsonResponse
    {
        $report = $this->probe->report();

        return new JsonResponse(
            Envelope::resource($report->toArray()),
            $report->isHealthy()
                ? Response::HTTP_OK
                : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
