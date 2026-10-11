import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';

export interface Money {
  amount: number;
  currency: string;
}

export interface CartLine {
  id: string;
  menu_item_id: string;
  name: string;
  quantity: number;
  unit_price: Money;
  line_subtotal: Money;
}

export interface Cart {
  restaurant_id: string | null;
  currency: string;
  lines: CartLine[];
  line_subtotal: Money;
  updated_at: string;
}

export function useCart() {
  return useQuery({
    queryKey: ['cart'],
    queryFn: () => apiFetch<Cart>('/cart'),
  });
}

export function useAddToCart() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { menu_item_id: string; quantity: number }) =>
      apiFetch<Cart>('/cart/items', { method: 'POST', body: input }),
    onSuccess: (data) => qc.setQueryData(['cart'], data),
  });
}

export function useSetLineQuantity() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { lineId: string; quantity: number }) =>
      apiFetch<Cart>(`/cart/lines/${input.lineId}`, { method: 'PATCH', body: { quantity: input.quantity } }),
    onSuccess: (data) => qc.setQueryData(['cart'], data),
  });
}

export function useRemoveLine() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (lineId: string) => apiFetch<Cart>(`/cart/lines/${lineId}`, { method: 'DELETE' }),
    onSuccess: (data) => qc.setQueryData(['cart'], data),
  });
}

export function money(m: Money | undefined): string {
  if (!m) return '';
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: m.currency }).format(m.amount / 100);
}
