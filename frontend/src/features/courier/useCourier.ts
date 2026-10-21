import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import type { Money } from '../cart/useCart';

export interface RiderProfile {
  vehicle_type: string | null;
  phone: string | null;
  rider_status: 'online' | 'offline' | 'on_delivery' | 'on_break' | 'suspended';
}

export interface Job {
  id: string;
  order_id: string;
  order_number: string | null;
  status: string;
  order_status: string | null;
  order_total: Money | null;
  delivery_address: Record<string, unknown> | null;
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
