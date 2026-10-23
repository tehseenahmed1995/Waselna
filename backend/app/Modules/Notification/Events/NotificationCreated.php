<?php

declare(strict_types=1);

namespace App\Modules\Notification\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast when a Notification is recorded for a user, so their notification
 * bell updates in real time. Carried on a PRIVATE per-user channel
 * (`user.{id}`), authorized by the bearer-token broadcasting auth endpoint so a
 * user only ever receives their own notifications.
 */
final class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $id,
        public readonly string $category,
        public readonly ?string $title,
        public readonly ?string $body,
        public readonly ?array $data,
        public readonly string $createdAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'title' => $this->title,
            'body' => $this->body,
            'status' => 'sent',
            'data' => $this->data,
            'created_at' => $this->createdAt,
        ];
    }
}
