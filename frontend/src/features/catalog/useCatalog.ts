import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';

export interface Restaurant {
  id: string;
  name: string;
  description: string | null;
  cuisine: string | null;
  currency: string;
  delivery_fee: { minor_units: number; currency: string };
  rating: number | null;
}

export interface MenuItem {
  id: string;
  name: string;
  description: string | null;
  price: { minor_units: number; currency: string };
  available: boolean;
}

export function useRestaurants() {
  return useQuery({
    queryKey: ['restaurants'],
    queryFn: () => apiFetch<Restaurant[]>('/restaurants', { auth: false }),
  });
}

export function useMenu(restaurantId: string | null) {
  return useQuery({
    queryKey: ['menu', restaurantId],
    queryFn: () =>
      apiFetch<{ restaurant_id: string; currency: string; items: MenuItem[] }>(
        `/restaurants/${restaurantId}/menu`,
        { auth: false },
      ),
    enabled: restaurantId !== null,
  });
}

export function formatMoney(minor: number, currency: string): string {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(minor / 100);
}
