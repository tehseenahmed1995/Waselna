import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';

export interface Restaurant {
  id: string;
  name: string;
  description: string | null;
  currency: string;
  rating: number | null;
  cuisine_tags: string[];
}

export interface MenuItem {
  id: string;
  name: string;
  description: string | null;
  price: { minor_units: number; currency: string };
  is_available: boolean;
}

export interface RestaurantDetail extends Restaurant {
  menu: { id: string; name: string; items: MenuItem[] }[];
}

export function useRestaurants() {
  return useQuery({
    queryKey: ['restaurants'],
    queryFn: () => apiFetch<Restaurant[]>('/restaurants'),
  });
}

export function useRestaurant(id: string | null) {
  return useQuery({
    queryKey: ['restaurant', id],
    queryFn: () => apiFetch<RestaurantDetail>(`/restaurants/${id}`),
    enabled: id !== null,
  });
}
