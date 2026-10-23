import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import type { Money } from '../cart/useCart';

export interface OrderBreakdown {
  line_subtotal: Money;
  delivery_fee: Money;
  service_fee: Money;
  tax_amount: Money;
  discount_amount: Money;
  order_total: Money;
}

export interface OrderLine {
  id: string;
  menu_item_id: string;
  quantity: number;
  unit_price: Money;
  subtotal: Money;
  snapshot: { name?: string; description?: string } | null;
}

export interface TimelineEntry {
  status: string;
  actor_role: string | null;
  notes: string | null;
  at: string;
}

export interface PaymentSummary {
  status: 'none' | 'pending' | 'authorized' | 'captured' | 'declined' | 'failed' | 'refunded';
  amount: Money;
  transaction_id: string | null;
}

export interface Order {
  id: string;
  order_number: string;
  status: string;
  restaurant_id: string;
  currency: string;
  breakdown: OrderBreakdown;
  lines: OrderLine[];
  delivery_address: Record<string, unknown>;
  created_at: string;
  payment?: PaymentSummary;
  timeline?: TimelineEntry[];
}

export function useOrders() {
  return useQuery({
    queryKey: ['orders'],
    queryFn: () => apiFetch<Order[]>('/orders'),
  });
}

export function useOrder(id: string | null) {
  return useQuery({
    queryKey: ['order', id],
    queryFn: () => apiFetch<Order>(`/orders/${id}`),
    enabled: id !== null,
    refetchInterval: 4000,
  });
}

export function useCheckout() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { address: { line1: string; city: string }; notes?: string }) =>
      apiFetch<Order>('/orders', {
        method: 'POST',
        body: { delivery_address: input.address, notes: input.notes, payment_method: 'cash_on_delivery' },
        headers: { 'Idempotency-Key': crypto.randomUUID() },
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['orders'] });
      qc.invalidateQueries({ queryKey: ['cart'] });
    },
  });
}

export function usePay() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (orderId: string) => apiFetch<unknown>(`/orders/${orderId}/pay`, { method: 'POST' }),
    onSuccess: (_data, orderId) => {
      qc.invalidateQueries({ queryKey: ['order', orderId] });
      qc.invalidateQueries({ queryKey: ['orders'] });
    },
  });
}
