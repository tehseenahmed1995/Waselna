import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import type { Money } from '../cart/useCart';

export interface RiderProfile {
  vehicle_type: string | null;
  phone: string | null;
  rider_status: 'online' | 'offline' | 'on_delivery' | 'on_break' | 'suspended';
}

export interface GeoPoint {
  latitude: number;
  longitude: number;
}

export interface Job {
  id: string;
  order_id: string;
  order_number: string | null;
  status: string;
  order_status: string | null;
  order_total: Money | null;
  item_count: number;
  restaurant_name: string | null;
  delivery_address: Record<string, unknown> | null;
  pickup_location: GeoPoint | null;
  dropoff_location: GeoPoint | null;
}

export function useRiderProfile() {
  return useQuery({ queryKey: ['rider-profile'], queryFn: () => apiFetch<RiderProfile>('/rider/profile') });
}

export function useAvailableJobs(enabled: boolean) {
  return useQuery({
    queryKey: ['dispatch-available'],
    queryFn: () => apiFetch<Job[]>('/dispatch/available'),
    enabled,
    refetchInterval: 5000,
  });
}

export function useActiveJob() {
  return useQuery({
    queryKey: ['dispatch-active'],
    queryFn: () => apiFetch<Job | null>('/dispatch/active'),
    refetchInterval: 5000,
  });
}

function invalidateAll(qc: ReturnType<typeof useQueryClient>) {
  qc.invalidateQueries({ queryKey: ['rider-profile'] });
  qc.invalidateQueries({ queryKey: ['dispatch-available'] });
  qc.invalidateQueries({ queryKey: ['dispatch-active'] });
}

export function useUpdateRiderProfile() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { vehicle_type?: string; phone?: string }) =>
      apiFetch<RiderProfile>('/rider/profile', { method: 'PATCH', body: input }),
    onSuccess: () => invalidateAll(qc),
  });
}

export function useToggleOnline() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (online: boolean) => apiFetch<RiderProfile>(online ? '/rider/online' : '/rider/offline', { method: 'POST' }),
    onSuccess: () => invalidateAll(qc),
  });
}

export function useJobAction(action: 'accept' | 'pickup' | 'transit' | 'deliver') {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (jobId: string) => apiFetch<Job>(`/dispatch/jobs/${jobId}/${action}`, { method: 'POST' }),
    onSuccess: () => invalidateAll(qc),
  });
}

// ---- Delivery history -------------------------------------------------------
export interface CourierJobHistory extends Job {
  delivered_at: string | null;
  created_at: string | null;
}

export interface CourierHistoryFilters {
  status?: string;
  from?: string;
  to?: string;
}

export function useCourierHistory(filters: CourierHistoryFilters, enabled: boolean) {
  const params = new URLSearchParams();
  if (filters.status) params.set('status', filters.status);
  if (filters.from) params.set('from', filters.from);
  if (filters.to) params.set('to', filters.to);
  const qs = params.toString();

  return useQuery({
    queryKey: ['dispatch-history', filters],
    queryFn: () => apiFetch<CourierJobHistory[]>(`/dispatch/history${qs ? `?${qs}` : ''}`),
    enabled,
  });
}
