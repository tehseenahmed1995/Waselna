import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';

export interface RestaurantProfile {
  id: string;
  name: string;
  description: string | null;
  status: string;
  currency: string;
  tax_rate_bp: number;
  base_delivery_fee: number;
  time_zone: string;
  cuisine_tags: string[];
}

export interface MenuCategory {
  id: string;
  restaurant_id: string;
  name: string;
  display_order: number;
  archived: boolean;
}

export interface MenuItem {
  id: string;
  restaurant_id: string;
  category_id: string;
  name: string;
  description: string | null;
  price_minor: number;
  is_available: boolean;
  display_order: number;
  archived: boolean;
}

// ---- Profile ----------------------------------------------------------------
export function useRestaurantProfile(restaurantId: string | null) {
  return useQuery({
    queryKey: ['restaurant-profile', restaurantId],
    queryFn: () => apiFetch<RestaurantProfile>(`/restaurants/${restaurantId}/profile`),
    enabled: restaurantId !== null,
  });
}

export function useUpdateRestaurantProfile(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (patch: Partial<RestaurantProfile> & { cuisine_tags: string[] }) =>
      apiFetch<RestaurantProfile>(`/restaurants/${restaurantId}/profile`, { method: 'PATCH', body: patch }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['restaurant-profile', restaurantId] });
    },
  });
}

export function useSubmitForReview(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => apiFetch<RestaurantProfile>(`/restaurants/${restaurantId}/submit-for-review`, { method: 'POST' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['restaurant-profile', restaurantId] }),
  });
}

// ---- Menu categories --------------------------------------------------------
export function useMenuCategories(restaurantId: string | null) {
  return useQuery({
    queryKey: ['menu-categories', restaurantId],
    queryFn: () => apiFetch<MenuCategory[]>(`/restaurants/${restaurantId}/menu/categories`),
    enabled: restaurantId !== null,
  });
}

export function useCreateCategory(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { name: string; display_order: number }) =>
      apiFetch<MenuCategory>(`/restaurants/${restaurantId}/menu/categories`, { method: 'POST', body: input }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['menu-categories', restaurantId] }),
  });
}

export function useUpdateCategory(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { id: string; name: string; display_order: number }) =>
      apiFetch<MenuCategory>(`/restaurants/${restaurantId}/menu/categories/${input.id}`, {
        method: 'PATCH',
        body: { name: input.name, display_order: input.display_order },
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['menu-categories', restaurantId] }),
  });
}

// ---- Menu items -------------------------------------------------------------
export function useMenuItems(restaurantId: string | null, categoryId: string | null) {
  return useQuery({
    queryKey: ['menu-items', restaurantId, categoryId],
    queryFn: () => apiFetch<MenuItem[]>(`/restaurants/${restaurantId}/menu/categories/${categoryId}/items`),
    enabled: restaurantId !== null && categoryId !== null,
  });
}

export interface NewMenuItem {
  category_id: string;
  name: string;
  description?: string | null;
  price_minor: number;
  is_available?: boolean;
  display_order: number;
}

export function useCreateItem(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: NewMenuItem) =>
      apiFetch<MenuItem>(`/restaurants/${restaurantId}/menu/items`, { method: 'POST', body: input }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['menu-items', restaurantId] }),
  });
}

export function useUpdateItem(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { id: string } & NewMenuItem) =>
      apiFetch<MenuItem>(`/restaurants/${restaurantId}/menu/items/${input.id}`, {
        method: 'PATCH',
        body: {
          category_id: input.category_id,
          name: input.name,
          description: input.description ?? null,
          price_minor: input.price_minor,
          display_order: input.display_order,
        },
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['menu-items', restaurantId] }),
  });
}

export function useDeleteItem(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (itemId: string) =>
      apiFetch(`/restaurants/${restaurantId}/menu/items/${itemId}`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['menu-items', restaurantId] }),
  });
}

export function useSetItemAvailability(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { itemId: string; is_available: boolean }) =>
      apiFetch<MenuItem>(`/restaurants/${restaurantId}/menu/items/${input.itemId}/availability`, {
        method: 'PATCH',
        body: { is_available: input.is_available },
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['menu-items', restaurantId] }),
  });
}