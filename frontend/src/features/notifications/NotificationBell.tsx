import { useState } from 'react';
import { useNotifications, useMarkNotificationsRead, useRealtimeNotifications } from './useNotifications';
import { useCurrentUser } from '../auth/useAuth';

export function NotificationBell({
  onOpenRestaurant,
}: {
  onOpenRestaurant?: (restaurantId: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const notifications = useNotifications();
  const markRead = useMarkNotificationsRead();
  const me = useCurrentUser();
  useRealtimeNotifications(me.data?.id);

  const items = notifications.data ?? [];
  const unread = items.filter((n) => n.status === 'sent').length;

  const toggle = () => {
    const next = !open;
    setOpen(next);
    if (next && unread > 0) markRead.mutate();
  };

  const restaurantIdOf = (data: Record<string, unknown> | null): string | null => {
    const id = data?.restaurant_id;
    return typeof id === 'string' ? id : null;
  };

  return (
    <div style={{ position: 'relative' }}>
      <button className="btn btn-ghost btn-sm" onClick={toggle} aria-label="Notifications">
        🔔
        {unread > 0 && (
          <span
            style={{
              background: 'var(--primary)',
              color: '#fff',
              borderRadius: 999,
              padding: '0 6px',
              marginLeft: 6,
              fontSize: 11,
            }}
          >
            {unread}
          </span>
        )}
      </button>

      {open && (
        <div
          className="card"
          style={{ position: 'absolute', right: 0, top: 40, width: 320, zIndex: 20, boxShadow: 'var(--shadow-lg)' }}
        >
          <div className="card-body" style={{ maxHeight: 360, overflow: 'auto' }}>
            <h3 style={{ marginBottom: 10 }}>Notifications</h3>
            {items.length === 0 && <div className="empty">Nothing yet.</div>}
            {items.map((n) => {
              const restaurantId = restaurantIdOf(n.data);
              const actionable = n.category === 'restaurant_registration' && restaurantId && onOpenRestaurant;
              return (
                <div key={n.id} style={{ padding: '8px 0', borderBottom: '1px solid var(--border)' }}>
                  <strong style={{ fontSize: 13 }}>{n.title}</strong>
                  <div className="subtle">{n.body}</div>
                  <div className="subtle" style={{ fontSize: 11 }}>{new Date(n.created_at).toLocaleTimeString()}</div>
                  {actionable && (
                    <button
                      className="btn btn-primary btn-sm mt-2"
                      onClick={() => {
                        onOpenRestaurant!(restaurantId!);
                        setOpen(false);
                      }}
                    >
                      Review restaurant →
                    </button>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}