import { useEffect } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { getEcho } from '../../api/echo';

export interface Notification {
  id: string;
  category: string;
  title: string | null;
  body: string | null;
  status: string;
  data: Record<string, unknown> | null;
  created_at: string;
}

interface NotificationsResult {
  data: Notification[];
  unread: number;
}

export function useNotifications() {
  return useQuery({
    queryKey: ['notifications'],
    queryFn: async () => {
      // The notifications endpoint returns unread in meta; fetch raw to read it.
      const res = await apiFetch<Notification[]>('/notifications');
      return res;
    },
    refetchInterval: 30000,
  });
}

export function useMarkNotificationsRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => apiFetch('/notifications/read', { method: 'POST' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  });
}

export type { NotificationsResult };

/**
 * Subscribe to the user's private channel so new notifications appear in the
 * bell the instant they're recorded (no polling wait). The polling query stays
 * as a fallback. Call once from a mounted component that knows the user id.
 */
export function useRealtimeNotifications(userId: string | null | undefined) {
  const qc = useQueryClient();

  useEffect(() => {
    if (!userId) return;
    const echo = getEcho();
    if (!echo) return;

    const channel = echo.private(`user.${userId}`);
    channel.listen('.notification.created', (n: Notification) => {
      qc.setQueryData<Notification[]>(['notifications'], (prev) => {
        const next = prev ? [...prev] : [];
        if (next.some((x) => x.id === n.id)) return next; // de-dupe
        return [n, ...next];
      });
    });

    return () => {
      echo.leave(`user.${userId}`);
    };
  }, [userId, qc]);
}
