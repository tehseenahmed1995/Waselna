import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';

export interface Notification {
  id: string;
  category: string;
  title: string | null;
  body: string | null;
  status: string;
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
    refetchInterval: 10000,
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
