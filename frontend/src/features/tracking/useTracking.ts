import { useEffect } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { getEcho } from '../../api/echo';

export interface LatLng {
  latitude: number;
  longitude: number;
}

export interface OrderTracking {
  order_id: string;
  order_number: string;
  status: string;
  job_status: string | null;
  pickup: LatLng | null;
  dropoff: LatLng | null;
  courier_location: (LatLng & { updated_at: string | null }) | null;
  has_courier: boolean;
}

// Statuses where a live map is meaningful (a courier is or will be moving).
const TRACKABLE = ['assigned_to_rider', 'picked_up', 'out_for_delivery'];

export function isTrackable(status: string): boolean {
  return TRACKABLE.includes(status);
}

export function useOrderTracking(orderId: string | null, enabled: boolean) {
  const qc = useQueryClient();

  const query = useQuery({
    queryKey: ['order-tracking', orderId],
    queryFn: () => apiFetch<OrderTracking>(`/orders/${orderId}/tracking`),
    enabled: orderId !== null && enabled,
    // Polling is the fallback; the websocket pushes updates in between. A slower
    // interval keeps things correct if the socket drops.
    refetchInterval: 15000,
  });

  // Live updates over Reverb: patch the cached tracking data in place when the
  // courier reports a new position, so the map moves without waiting for a poll.
  useEffect(() => {
    if (!orderId || !enabled) return;
    const echo = getEcho();
    if (!echo) return;

    const channel = echo.private(`order.${orderId}`);
    channel.listen('.courier.location', (e: { latitude: number; longitude: number; order_status: string }) => {
      qc.setQueryData<OrderTracking>(['order-tracking', orderId], (prev) =>
        prev
          ? {
              ...prev,
              status: e.order_status ?? prev.status,
              has_courier: true,
              courier_location: { latitude: e.latitude, longitude: e.longitude, updated_at: new Date().toISOString() },
            }
          : prev,
      );
    });

    return () => {
      echo.leave(`order.${orderId}`);
    };
  }, [orderId, enabled, qc]);

  return query;
}

export function useReportLocation() {
  return useMutation({
    mutationFn: (loc: LatLng) =>
      apiFetch<{ status: string }>('/tracking/location', { method: 'POST', body: loc }),
  });
}