<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Dispatch\Services\DispatchService;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DispatchController
{
    public function __construct(private readonly DispatchService $dispatch) {}

    public function available(Request $request): JsonResponse
    {
        $data = $this->dispatch->availableJobs($this->actor($request));

        return new JsonResponse(['data' => $data, 'meta' => ['total' => count($data), 'next_cursor' => null]], 200);
    }

    public function active(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->dispatch->activeJob($this->actor($request))], 200);
    }

    public function accept(Request $request, string $job): JsonResponse
    {
        return new JsonResponse(['data' => $this->dispatch->accept($this->actor($request), $job)], 200);
    }

    public function pickup(Request $request, string $job): JsonResponse
    {
        return new JsonResponse(['data' => $this->dispatch->markPickedUp($this->actor($request), $job)], 200);
    }

    public function transit(Request $request, string $job): JsonResponse
    {
        return new JsonResponse(['data' => $this->dispatch->markInTransit($this->actor($request), $job)], 200);
    }

    public function deliver(Request $request, string $job): JsonResponse
    {
        return new JsonResponse(['data' => $this->dispatch->markDelivered($this->actor($request), $job)], 200);
    }

    private function actor(Request $request): ActorContext
    {
        $actor = $request->attributes->get('actor');

        if (! $actor instanceof ActorContext) {
            throw AuthorizationException::unauthenticated();
        }

        return $actor;
    }
}
