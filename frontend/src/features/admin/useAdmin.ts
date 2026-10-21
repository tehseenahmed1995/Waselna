import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import type { Money } from '../cart/useCart';

export interface AdminSummary {
  orders_total: number;
  orders_by_status: Record<string, number>;
  gross_revenue: Money;
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

export function useAdminSummary() {
  return useQuery({ queryKey: ['admin-summary'], queryFn: () => apiFetch<AdminSummary>('/admin/summary'), refetchInterval: 8000 });
}

export function useAdminOrders() {
  return useQuery({ queryKey: ['admin-orders'], queryFn: () => apiFetch<AdminOrder[]>('/admin/orders'), refetchInterval: 8000 });
}

export function useAdminCouriers() {
  return useQuery({ queryKey: ['admin-couriers'], queryFn: () => apiFetch<AdminCourier[]>('/admin/couriers') });
}
