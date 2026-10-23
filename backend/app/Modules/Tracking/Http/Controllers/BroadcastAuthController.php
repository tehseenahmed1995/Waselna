<?php

declare(strict_types=1);

namespace App\Modules\Tracking\Http\Controllers;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Order\Models\Order;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bearer-token broadcasting auth for private channels.
 *
 * The Platform authenticates with opaque bearer tokens, not Laravel sessions, so
 * the framework's cookie-based /broadcasting/auth cannot be used. This endpoint
 * runs behind the same AuthenticateBearer middleware as the rest of the API: it
 * resolves the ActorContext, authorizes the requested channel, and returns the
 * Pusher-protocol auth signature Reverb expects. Only `private-order.{id}`
 * channels are grantable, and only to the Order's Customer, the Restaurant's
 * operator/staff, or an Administrator.
 */
final class BroadcastAuthController
{
    public function authenticate(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        $channel = (string) $request->input('channel_name', '');
        $socketId = (string) $request->input('socket_id', '');

        if ($channel === '' || $socketId === '') {
            throw AuthorizationException::forbidden();
        }

        // Grant private order channels (tracking) and private user channels
        // (that user's own notifications); reject anything else.
        if (str_starts_with($channel, 'private-order.')) {
            $orderId = substr($channel, strlen('private-order.'));
            if (! $this->canAccessOrder($actor, $orderId)) {
                throw AuthorizationException::forbidden();
            }
        } elseif (str_starts_with($channel, 'private-user.')) {
            $userId = substr($channel, strlen('private-user.'));
            if ($userId !== $actor->userId) {
                throw AuthorizationException::forbidden();
            }
        } else {
            throw AuthorizationException::forbidden();
        }

        /** @var array{key: string, secret: string} $app */
        $app = [
            'key' => (string) config('broadcasting.connections.reverb.key'),
            'secret' => (string) config('broadcasting.connections.reverb.secret'),
        ];

        // Pusher-protocol private-channel signature: HMAC-SHA256 of
        // "socket_id:channel_name" keyed by the app secret, prefixed with the key.
        $signature = hash_hmac('sha256', $socketId.':'.$channel, $app['secret']);
        $auth = $app['key'].':'.$signature;

        return new JsonResponse(['auth' => $auth], 200);
    }

    private function canAccessOrder(ActorContext $actor, string $orderId): bool
    {
        /** @var Order|null $order */
        $order = Order::query()->find($orderId);

        if ($order === null) {
            return false;
        }

        return match ($actor->role) {
            'customer' => $order->customer_id === $actor->userId,
            'restaurant_operator', 'restaurant_staff' => $order->restaurant_id === $actor->restaurantId,
            'administrator' => true,
            default => false,
        };
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
