<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Notification\Services\NotificationService;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $this->notifications->list($actor);

        return new JsonResponse([
            'data' => $data,
            'meta' => ['total' => count($data), 'next_cursor' => null, 'unread' => $this->notifications->unreadCount($actor)],
        ], 200);
    }

    public function markRead(Request $request): JsonResponse
    {
        $this->notifications->markAllRead($this->actor($request));

        return new JsonResponse(['data' => ['status' => 'ok']], 200);
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
