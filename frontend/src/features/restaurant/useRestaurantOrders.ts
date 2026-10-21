import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import type { Money } from '../cart/useCart';

export interface QueueOrder {
  id: string;
  order_number: string;
  status: string;
  currency: string;
  order_total: Money;
  line_count: number;
  lines: { quantity: number; name: string }[];
  created_at: string;
}

export function useRestaurantQueue() {
  return useQuery({
    queryKey: ['restaurant-queue'],
    queryFn: () => apiFetch<QueueOrder[]>('/restaurant/orders'),
    refetchInterval: 5000,
  });
}

function useQueueAction(path: (id: string) => string, method = 'POST') {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { orderId: string; body?: unknown }) =>
      apiFetch(path(input.orderId), { method, body: input.body }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['restaurant-queue'] }),
  });
}

export const useAcceptOrder = () => useQueueAction((id) => `/restaurant/orders/${id}/accept`);
export const useReadyOrder = () => useQueueAction((id) => `/restaurant/orders/${id}/ready`);

export function useRejectOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { orderId: string; reason: string }) =>
      apiFetch(`/restaurant/orders/${input.orderId}/reject`, { method: 'POST', body: { reason: input.reason } }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['restaurant-queue'] }),
  });
}
