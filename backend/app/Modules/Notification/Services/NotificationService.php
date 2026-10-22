<?php

declare(strict_types=1);

namespace App\Modules\Notification\Services;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Notification\Models\Notification;
use App\Shared\Support\Clock;
use Illuminate\Support\Str;

/**
 * In-app notifications (Requirement 16, demo slice). Order transitions record a
 * recipient-scoped in-app Notification; the recipient can list them and mark
 * them read.
 */
final class NotificationService
{
    public function __construct(private readonly Clock $clock) {}

    public function record(string $userId, string $category, string $title, string $body, ?array $data = null): void
    {
        $now = $this->clock->nowUtc();

        Notification::query()->create([
            'id' => (string) Str::ulid(),
            'user_id' => $userId,
            'category' => $category,
            'channel' => 'in_app',
            'status' => 'sent',
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'sent_at' => $now,
            'created_at' => $now,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(ActorContext $actor): array
    {
        return Notification::query()
            ->where('user_id', $actor->userId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (Notification $n): array => [
                'id' => $n->id,
                'category' => $n->category,
                'title' => $n->title,
                'body' => $n->body,
                'status' => $n->status,
                'data' => $n->data,
                'created_at' => $n->created_at,
            ])->all();
    }

    public function unreadCount(ActorContext $actor): int
    {
        return Notification::query()
            ->where('user_id', $actor->userId)
            ->where('status', 'sent')
            ->count();
    }

    public function markAllRead(ActorContext $actor): void
    {
        Notification::query()
            ->where('user_id', $actor->userId)
            ->where('status', 'sent')
            ->update(['status' => 'read', 'read_at' => $this->clock->nowUtc()]);
    }
}
