import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import type { Money } from '../cart/useCart';

export interface AdminSummary {
  orders_total: number;
  orders_today: number;
  orders_by_status: Record<string, number>;
  gross_revenue: Money;
  gross_revenue_today: Money;
  restaurants: number;
  couriers: number;
  active_deliveries: number;
}

export interface AdminOrder {
  id: string;
  order_number: string;
  status: string;
  customer_id: string;
  restaurant_id: string;
  order_total: Money;
  line_count: number;
  created_at: string;
}

export interface AdminCourier {
  id: string;
  display_name: string;
  email: string;
  account_status: string;
  rider_status: string;
  vehicle_type: string | null;
}

export interface AdminRestaurant {
  id: string;
  name: string;
  description?: string | null;
  status: string;
  status_label?: string;
  // Backend-derived: whether the restaurant is live in customer search.
  is_discoverable?: boolean;
  currency: string;
  tax_rate_bp?: number;
  base_delivery_fee?: number;
  rating: number | null;
  menu_items?: number;
}

export interface NewRestaurant {
  name: string;
  description?: string;
  cuisine?: string;
  currency?: string;
  tax_rate_bp?: number;
  base_delivery_fee?: number;
  address?: string;
  published?: boolean;
}

export function useAdminSummary() {
  return useQuery({ queryKey: ['admin-summary'], queryFn: () => apiFetch<AdminSummary>('/admin/summary'), refetchInterval: 8000 });
}

export function useAdminOrders() {
  return useQuery({ queryKey: ['admin-orders'], queryFn: () => apiFetch<AdminOrder[]>('/admin/orders'), refetchInterval: 8000 });
}

export function useAdminCouriers() {
  return useQuery({ queryKey: ['admin-couriers'], queryFn: () => apiFetch<AdminCourier[]>('/admin/couriers') });
}

export function useAdminRestaurants() {
  return useQuery({ queryKey: ['admin-restaurants'], queryFn: () => apiFetch<AdminRestaurant[]>('/admin/restaurants') });
}

export function useCreateRestaurant() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: NewRestaurant) => apiFetch<AdminRestaurant>('/admin/restaurants', { method: 'POST', body: input }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-restaurants'] });
      qc.invalidateQueries({ queryKey: ['admin-summary'] });
    },
  });
}

export function useSetRestaurantVisibility() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { id: string; action: 'suspend' | 'publish' }) =>
      apiFetch<AdminRestaurant>(`/admin/restaurants/${input.id}/${input.action}`, { method: 'POST' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-restaurants'] }),
  });
}

export function useUpdateRestaurant() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { id: string; patch: Partial<NewRestaurant> }) =>
      apiFetch<AdminRestaurant>(`/admin/restaurants/${input.id}`, { method: 'PATCH', body: input.patch }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-restaurants'] }),
  });
}

export function useDeleteRestaurant() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => apiFetch<{ deleted: boolean }>(`/admin/restaurants/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-restaurants'] });
      qc.invalidateQueries({ queryKey: ['admin-summary'] });
    },
  });
}